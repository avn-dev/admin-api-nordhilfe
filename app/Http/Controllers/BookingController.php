<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\Course;
use App\Models\PaypalToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    /**
     * Returns API base URL depending on environment.
     */
    private function getPaypalBaseUrl(): string
    {
        // config/services.php: 'paypal' => ['mode' => 'sandbox'|'live', ...]
        $mode = config('services.paypal.mode', 'sandbox');
        return $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Create booking / PayPal order.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstName'      => 'required|string|max:100',
            'lastName'       => 'required|string|max:100',
            'email'          => 'required|email|max:255',
            'phone'          => 'nullable|string|max:100',
            'birthDate'      => 'required|date|before:today',
            'course'         => 'required|integer|exists:courses,id',
            'date'           => 'required|integer', // training_session_id
            'paymentMethod'  => 'required|in:paypal,onsite',
            'returnUrl'      => 'required|url',
            'captchaToken'   => 'required|string',
            'visionTest'     => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
        ]);

        // Turnstile verify
        $captchaResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => config('services.turnstile.secret'),
            'response' => $validated['captchaToken'],
            'remoteip' => $request->ip(),
        ]);

        if (!($captchaResponse->json()['success'] ?? false)) {
            return response()->json(['message' => 'Captcha-Überprüfung fehlgeschlagen.'], 422);
        }

        $course         = Course::findOrFail($validated['course']);
        $visionTest     = (bool) ($validated['visionTest'] ?? false);
        $passportPhotos = (bool) ($validated['passportPhotos'] ?? false);

        // Preis berechnen
        $amount = $course->base_price;
        if ($visionTest) {
            $amount += 9;
        }
        if ($passportPhotos) {
            $amount += 9;
        }
        // Rabatt (3-in-1) Beispiel: Kurs-ID 1
        if ($visionTest && $passportPhotos && (int)$course->id === 1) {
            $amount = 65;
        }
        $amount = number_format($amount, 2, '.', '');

        if ($validated['paymentMethod'] === 'paypal') {
            // Create PayPal order
            $paypalBaseUrl = $this->getPaypalBaseUrl();

            // 1) OAuth token
            $accessToken = Http::withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.secret')
            )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ])->throw()->json('access_token');

            // 2) Create order (DO NOT add custom ?token=...; PayPal will append token={ORDER_ID})
            $orderRes = Http::withToken($accessToken)->post($paypalBaseUrl . '/v2/checkout/orders', [
                'intent'          => 'CAPTURE',
                'purchase_units'  => [[
                    'amount'      => ['currency_code' => 'EUR', 'value' => $amount],
                    'description' => 'Kursanmeldung: ' . $course->name .
                        ($visionTest ? ', Sehtest' : '') .
                        ($passportPhotos ? ', Passfotos' : ''),
                ]],
                'application_context' => [
                    'return_url' => $validated['returnUrl'],                 // PayPal adds token={ORDER_ID}
                    'cancel_url' => $validated['returnUrl'] . '?payment=cancelled',
                ],
            ])->throw();

            $order = $orderRes->json();
            $orderId = $order['id'] ?? null;
            if (!$orderId) {
                Log::error('PayPal order create response missing id', ['response' => $order]);
                return response()->json(['message' => 'Fehler bei PayPal.'], 502);
            }

            // Store orderId to link back when user returns
            PaypalToken::updateOrCreate(
                ['token' => $orderId],
                ['payload' => $validated, 'used' => false]
            );

            $approveUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
            if (!$approveUrl) {
                Log::error('PayPal approve link missing', ['response' => $order]);
                return response()->json(['message' => 'Fehler bei PayPal (approve URL fehlt).'], 502);
            }

            return response()->json(['paypal_url' => $approveUrl]);
        }

        // Onsite cash/card
        $participant = Participant::create([
            'first_name'         => $validated['firstName'],
            'last_name'          => $validated['lastName'],
            'email'              => $validated['email'],
            'birth_date'         => $validated['birthDate'],
            'phone'              => $validated['phone'],
            'training_session_id'=> $validated['date'],
            'vision_test'        => $visionTest,
            'passport_photos'    => $passportPhotos,
        ]);

        $participant->payments()->create([
            'method'   => 'onsite',
            'status'   => 'unpaid',
            'amount'   => $amount,
            'currency' => 'EUR',
        ]);

        return response()->json(['message' => 'Anmeldung gespeichert (Zahlung vor Ort).']);
    }

    /**
     * Called after PayPal redirects back with ?token={ORDER_ID}&PayerID=...
     * Captures the order, then creates the participant and payment.
     */
    public function paypalComplete(Request $request)
    {
        $orderId = $request->input('token'); // PayPal ORDER_ID from return_url
        if (!$orderId) {
            return response()->json(['message' => 'Fehlender PayPal-Token.'], 400);
        }

        $token = PaypalToken::where('token', $orderId)->first();
        if (!$token) {
            return response()->json(['message' => 'Ungültiger oder abgelaufener Token.'], 400);
        }
        if ($token->used) {
            // Idempotent: if we already handled it, return OK
            return response()->json(['message' => 'Buchung bereits verarbeitet.']);
        }

        $data           = $token->payload;
        $course         = Course::findOrFail($data['course']);
        $visionTest     = (bool) ($data['visionTest'] ?? false);
        $passportPhotos = (bool) ($data['passportPhotos'] ?? false);

        // Preis neu berechnen (source of truth)
        $amount = $course->base_price;
        if ($visionTest) $amount += 9;
        if ($passportPhotos) $amount += 9;
        if ($visionTest && $passportPhotos && (int)$course->id === 1) $amount = 65;
        $amount = number_format($amount, 2, '.', '');

        // Capture the order
        $paypalBaseUrl = $this->getPaypalBaseUrl();

        try {
            $accessToken = Http::withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.secret')
            )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ])->throw()->json('access_token');

            $captureRes = Http::withToken($accessToken)
                ->post($paypalBaseUrl . "/v2/checkout/orders/{$orderId}/capture")
                ->throw();

            $capture = $captureRes->json();
            $status  = $capture['status'] ?? null;

            // Accept COMPLETED; also tolerate cases where capture already done
            if ($status !== 'COMPLETED') {
                Log::warning('Unexpected capture status', ['orderId' => $orderId, 'capture' => $capture]);
                return response()->json(['message' => 'Zahlung nicht abgeschlossen.', 'paypal' => $capture], 409);
            }

            // Extract useful info
            $pu = $capture['purchase_units'][0] ?? [];
            $cap = $pu['payments']['captures'][0] ?? [];
            $captureId = $cap['id'] ?? null;
            $paymentStatus = $cap['status'] ?? 'COMPLETED';
            $paidValue = $cap['amount']['value'] ?? $amount;
            $paidCurrency = $cap['amount']['currency_code'] ?? 'EUR';
        } catch (\Throwable $e) {
            Log::error('PayPal capture failed', ['orderId' => $orderId, 'e' => $e]);
            return response()->json(['message' => 'PayPal-Zahlung fehlgeschlagen.'], 502);
        }

        // Create participant after successful capture
        $participant = Participant::create([
            'first_name'          => $data['firstName'],
            'last_name'           => $data['lastName'],
            'email'               => $data['email'],
            'birth_date'          => $data['birthDate'],
            'phone'               => $data['phone'] ?? null,
            'training_session_id' => $data['date'],
            'vision_test'         => $visionTest,
            'passport_photos'     => $passportPhotos,
        ]);

        $participant->payments()->create([
            'method'        => 'paypal',
            'status'        => strtolower($paymentStatus) === 'completed' ? 'paid' : strtolower($paymentStatus),
            'amount'        => $paidValue,
            'currency'      => $paidCurrency,
            'external_id'   => $captureId,   // store PayPal capture id for reconciliation
            'meta'          => ['order_id' => $orderId],
        ]);

        // Mark token used (idempotency)
        $token->used = true;
        $token->save();

        return response()->json(['message' => 'Teilnehmer gespeichert und Zahlung erfasst.']);
    }
}
