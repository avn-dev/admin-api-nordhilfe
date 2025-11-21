<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use App\Models\Course;
use App\Models\PaypalToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    private function getPaypalBaseUrl()
    {
        return config('services.paypal.mode') === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function getPaypalAccessToken(): string
    {
        $paypalBaseUrl = $this->getPaypalBaseUrl();

        $resp = Http::withBasicAuth(
            config('services.paypal.client_id'),
            config('services.paypal.secret')
        )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if (!$resp->successful()) {
            Log::error('PayPal OAuth failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            abort(502, 'PayPal-Authentifizierung fehlgeschlagen.');
        }

        return $resp->json()['access_token'];
    }

    private function computeAmount(Course $course, bool $visionTest, bool $passportPhotos): string
    {
        $amount = $course->base_price;
        $discountCourse = Course::find(4);
        if ($visionTest) $amount += 9;
        if ($passportPhotos) $amount += 9;
        if ($visionTest && $passportPhotos && $course->id == 1) {
            $amount = $discountCourse->discounted ? $discountCourse->discount_price : $discountCourse->base_price; // Rabattpreis für Erste-Hilfe-Kurs + Sehtest + Passfotos
        }
        error_log("amount: {$amount}");
        return number_format($amount, 2, '.', '');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstName'     => 'required',
            'lastName'      => 'required',
            'address'       => 'required',
            'houseNumber'   => 'nullable',
            'city'          => 'required',
            'postCode'      => 'required',
            'email'         => 'required|email',
            'birthDate'     => 'required|date',
            'phone'         => 'nullable|string',
            'course'        => 'required|exists:courses,id',
            'date'          => 'required|exists:training_sessions,id',
            'paymentMethod' => 'required|in:paypal,onsite',
            'captchaToken'  => 'required|string',
            'returnUrl'     => 'required|url',
            'visionTest'    => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
        ]);

        // Turnstile prüfen
        $captchaResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => config('services.turnstile.secret'),
            'response' => $validated['captchaToken'],
            'remoteip' => $request->ip(),
        ]);

        if (!($captchaResponse->json()['success'] ?? false) && app()->environment('production')) {
            return response()->json(['message' => 'Captcha-Überprüfung fehlgeschlagen.'], 422);
        }

        $course        = Course::findOrFail($validated['course']);
        $visionTest    = (bool)($validated['visionTest'] ?? false);
        $passportPhotos = (bool)($validated['passportPhotos'] ?? false);
        $amount        = $this->computeAmount($course, $visionTest, $passportPhotos);

        if ($validated['paymentMethod'] === 'paypal') {
            // Eigener State (UUID), NICHT "token" nennen (Kollision mit PayPal).
            $state = Str::uuid()->toString();

            // Nutzlast für spätere Teilnehmer-Erstellung speichern
            $tokenRow = PaypalToken::create([
                'token'   => $state,
                'payload' => array_merge($validated, [
                    'visionTest'     => $visionTest,
                    'passportPhotos' => $passportPhotos,
                ]),
            ]);

            $paypalBaseUrl = $this->getPaypalBaseUrl();
            $accessToken   = $this->getPaypalAccessToken();

            // PayPal-Order erstellen
            $orderRes = Http::withToken($accessToken)->post($paypalBaseUrl . '/v2/checkout/orders', [
                'intent'          => 'CAPTURE',
                'purchase_units'  => [[
                    'amount'       => ['currency_code' => 'EUR', 'value' => $amount],
                    'description'  => 'Kursanmeldung: ' . $course->name .
                        ($visionTest ? ', Sehtest' : '') .
                        ($passportPhotos ? ', Passfotos' : ''),
                    'custom_id'    => $state, // Zuordnung
                    'invoice_id'   => 'BK-' . now()->format('YmdHis') . '-' . substr($state, 0, 8),
                ]],
                'application_context' => [
                    'return_url'    => $validated['returnUrl'] . '?payment=success&state=' . $state,
                    'cancel_url'    => $validated['returnUrl'] . '?payment=cancelled&state=' . $state,
                    'brand_name'    => config('app.name', 'Erste Hilfe'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action'   => 'PAY_NOW',
                ],
            ]);

            if (!$orderRes->successful()) {
                Log::error('PayPal order failed', ['status' => $orderRes->status(), 'body' => $orderRes->body()]);
                return response()->json(['message' => 'PayPal-Order konnte nicht erstellt werden.'], 502);
            }

            $order   = $orderRes->json();
            $orderId = $order['id'] ?? null;
            if (!$orderId) {
                Log::error('PayPal order id missing', ['body' => $order]);
                return response()->json(['message' => 'Unerwartete PayPal-Antwort.'], 502);
            }

            // Order-ID in Payload sichern (keine Migration nötig)
            $tokenRow->payload = array_merge($tokenRow->payload ?? [], ['paypal_order_id' => $orderId]);
            $tokenRow->save();

            $approveUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;
            if (!$approveUrl) {
                Log::error('PayPal approve link missing', ['body' => $order]);
                return response()->json(['message' => 'Freigabe-Link von PayPal fehlt.'], 502);
            }

            return response()->json(['paypal_url' => $approveUrl, 'state' => $state]);
        }

        // Bar-/EC-Zahlung vor Ort
        $participant = Participant::create([
            'first_name'         => $validated['firstName'],
            'last_name'          => $validated['lastName'],
            'address'            => $validated['address'],
            'house_number'       => $validated['houseNumber'],
            'city'               => $validated['city'],
            'post_code'          => $validated['postCode'],
            'email'              => $validated['email'],
            'birth_date'         => $validated['birthDate'],
            'phone'              => $validated['phone'],
            'training_session_id' => $validated['date'],
            'vision_test'        => $visionTest,
            'passport_photos'    => $passportPhotos,
        ]);

        $payment = $participant->payments()->create([
            'method'   => 'cash',
            'status'   => 'unpaid',
            'amount'   => $amount,
            'currency' => 'EUR',
        ]);

        dispatch(new \App\Jobs\SendBookingConfirmation($participant, [
            'payment_id' => $payment->id,
            'status'     => $payment->status,
            'method'     => 'Bar vor Ort',
            'amount'     => (float)$payment->amount,
        ]));

        return response()->json(['message' => 'Anmeldung erfolgreich']);
    }

    public function paypalComplete(Request $request)
    {
        $data = $request->validate([
            'orderId' => 'required|string', // PayPal Order-ID (kommt als ?token= von PayPal)
            'state'   => 'required|string', // unser eigener UUID-Token
        ]);

        $orderId = $data['orderId'];
        $state   = $data['state'];

        $token = PaypalToken::where('token', $state)->first();
        if (!$token) {
            return response()->json(['message' => 'Ungültiger oder fehlender state.'], 400);
        }
        if ($token->used) {
            return response()->json(['message' => 'Token wurde bereits verwendet.'], 409);
        }

        $payload = $token->payload ?? [];
        if (($payload['paypal_order_id'] ?? null) !== $orderId) {
            Log::warning('PayPal order id mismatch', ['stored' => $payload['paypal_order_id'] ?? null, 'given' => $orderId]);
            return response()->json(['message' => 'Order-ID stimmt nicht überein.'], 409);
        }

        $course         = Course::findOrFail($payload['course']);
        $visionTest     = (bool)($payload['visionTest'] ?? false);
        $passportPhotos = (bool)($payload['passportPhotos'] ?? false);
        $expectedAmount = $this->computeAmount($course, $visionTest, $passportPhotos);

        $paypalBaseUrl = $this->getPaypalBaseUrl();
        $accessToken   = $this->getPaypalAccessToken();

        // Order holen (Status & Betrag verifizieren)
        $orderGet = Http::withToken($accessToken)->get($paypalBaseUrl . '/v2/checkout/orders/' . $orderId);
        if (!$orderGet->successful()) {
            Log::error('PayPal get order failed', ['status' => $orderGet->status(), 'body' => $orderGet->body()]);
            return response()->json(['message' => 'PayPal-Order konnte nicht gelesen werden.'], 502);
        }
        $orderInfo     = $orderGet->json();
        $orderAmount   = data_get($orderInfo, 'purchase_units.0.amount.value');
        $orderCurrency = data_get($orderInfo, 'purchase_units.0.amount.currency_code');

        if ($orderCurrency !== 'EUR' || (string)$orderAmount !== (string)$expectedAmount) {
            Log::warning('PayPal amount/currency mismatch', [
                'expectedAmount' => $expectedAmount,
                'orderAmount'    => $orderAmount,
                'currency'       => $orderCurrency,
            ]);
            return response()->json(['message' => 'Betrag oder Währung stimmen nicht überein.'], 409);
        }

        // Capture ausführen (idempotent behandeln)
        $captureRes = Http::withToken($accessToken)
            ->withHeaders([
                // optional, aber empfohlen für Idempotenz & volle Antwort:
                'PayPal-Request-Id' => $state,                 // dein eigener UUID/state
                'Prefer'            => 'return=representation' // liefert vollständige Capture-Daten
            ])
            ->withBody('{}', 'application/json')               // <— wichtig: {} statt []
            ->post($paypalBaseUrl . '/v2/checkout/orders/' . $orderId . '/capture');

        $captureJson = $captureRes->json();

        // return $captureJson;

        if (!$captureRes->successful()) {
            // Falls bereits gecaptured
            $errName = $captureJson['name'] ?? null;
            if ($errName === 'ORDER_ALREADY_CAPTURED') {
                $orderGet2 = Http::withToken($accessToken)->get($paypalBaseUrl . '/v2/checkout/orders/' . $orderId);
                if (!$orderGet2->successful()) {
                    Log::error('PayPal get order after ORDER_ALREADY_CAPTURED failed', ['status' => $orderGet2->status(), 'body' => $orderGet2->body()]);
                    return response()->json(['message' => 'PayPal-Order konnte nach Capture nicht gelesen werden.'], 502);
                }
                $orderInfo = $orderGet2->json();
            } else {
                Log::error('PayPal capture failed', ['status' => $captureRes->status(), 'body' => $captureRes->body()]);
                return response()->json(['message' => 'PayPal-Capture fehlgeschlagen.'], 502);
            }
        } else {
            // Erfolgreicher Capture: PayPal liefert die Captures in der Antwort
            $orderInfo = $captureJson;
        }

        $capture     = data_get($orderInfo, 'purchase_units.0.payments.captures.0');
        $status      = $capture['status'] ?? ($orderInfo['status'] ?? null);
        if ($status !== 'COMPLETED') {
            Log::warning('PayPal status not completed', ['status' => $status, 'body' => $orderInfo]);
            return response()->json(['message' => 'Zahlung nicht abgeschlossen (Status: ' . ($status ?? 'unbekannt') . ').'], 409);
        }

        $captureId   = $capture['id'] ?? null;
        $paidAmount  = data_get($capture, 'amount.value', $orderAmount);
        $paidCurrency = data_get($capture, 'amount.currency_code', $orderCurrency);

        // Teilnehmer & Payment anlegen
        $participant = Participant::create([
            'first_name'          => $payload['firstName'],
            'last_name'           => $payload['lastName'],
            'address'             => $payload('address'),
            'house_number'        => $payload('houseNumber'),
            'city'                => $payload('city'),
            'post_code'           => $payload('postCode'),
            'email'               => $payload['email'],
            'birth_date'          => $payload['birthDate'],
            'phone'               => $payload['phone'] ?? null,
            'training_session_id' => $payload['date'],
            'vision_test'         => $visionTest,
            'passport_photos'     => $passportPhotos,
        ]);

        $payment = $participant->payments()->create([
            'method'      => 'paypal',
            'status'      => 'paid',
            'amount'      => $paidAmount,
            'currency'    => $paidCurrency,
            'external_id' => $captureId ?: $orderId,
            'meta'        => $capture ?: $orderInfo, // komplette Antwort für spätere Nachverfolgung
        ]);

        $orderNumber = data_get($orderInfo, 'purchase_units.0.invoice_id')
            ?? ('BK-' . now()->format('Ymd') . '-' . substr($state, 0, 8));

        dispatch(new \App\Jobs\SendBookingConfirmation($participant, [
            'payment_id'   => $payment->id,
            'status'       => 'paid',
            'method'       => 'PayPal',
            'amount'       => (float)$paidAmount,
            'order_number' => $orderNumber,
        ]));

        $token->used = true;
        $token->save();

        return response()->json(['message' => 'Teilnehmer gespeichert']);
    }
}
