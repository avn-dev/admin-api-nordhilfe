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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstName' => 'required',
            'lastName' => 'required',
            'email' => 'required|email',
            'birthDate' => 'required|date',
            'phone' => 'nullable|string',
            'course' => 'required|exists:courses,id',
            'date' => 'required|exists:training_sessions,id',
            'paymentMethod' => 'required|in:paypal,onsite',
            'captchaToken' => 'required|string',
            'returnUrl' => 'required|url',
            'visionTest' => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
        ]);

        // Turnstile prüfen
        $captchaResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret'),
            'response' => $validated['captchaToken'],
            'remoteip' => $request->ip(),
        ]);

        if (!($captchaResponse->json()['success'] ?? false)) {
            return response()->json(['message' => 'Captcha-Überprüfung fehlgeschlagen.'], 422);
        }

        $course = Course::findOrFail($validated['course']);
        $visionTest = $validated['visionTest'] ?? false;
        $passportPhotos = $validated['passportPhotos'] ?? false;

        // Preis berechnen
        $amount = $course->base_price;
        if ($visionTest) {
            $amount += 9;
        }
        if ($passportPhotos) {
            $amount += 9;
        }
        if ($visionTest && $passportPhotos && $course->id == 1) {
            $amount = 65;
        }
        $amount = number_format($amount, 2, '.', '');

        if ($validated['paymentMethod'] === 'paypal') {
            $token = Str::uuid()->toString();
            $returnUrl = $validated['returnUrl'] . '?payment=success&token=' . $token;
            $paypalBaseUrl = $this->getPaypalBaseUrl();

            try {
                // Get PayPal access token
                $accessTokenResponse = Http::withBasicAuth(
                    config('services.paypal.client_id'),
                    config('services.paypal.secret')
                )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

                if (!$accessTokenResponse->successful()) {
                    Log::error('PayPal access token request failed', [
                        'status' => $accessTokenResponse->status(),
                        'response' => $accessTokenResponse->json(),
                    ]);
                    return response()->json(['message' => 'Fehler bei der PayPal-Authentifizierung.'], 500);
                }

                $accessToken = $accessTokenResponse->json()['access_token'];

                // Create PayPal order
                $orderResponse = Http::withToken($accessToken)->post($paypalBaseUrl . '/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'amount' => ['currency_code' => 'EUR', 'value' => $amount],
                        'description' => 'Kursanmeldung: ' . $course->name .
                            ($visionTest ? ', Sehtest' : '') .
                            ($passportPhotos ? ', Passfotos' : ''),
                    ]],
                    'application_context' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $validated['returnUrl'] . '?payment=cancelled',
                    ],
                ]);

                if (!$orderResponse->successful()) {
                    Log::error('PayPal order creation failed', [
                        'status' => $orderResponse->status(),
                        'response' => $orderResponse->json(),
                        'debug_id' => $orderResponse->json()['debug_id'] ?? null,
                    ]);
                    return response()->json(['message' => 'Fehler beim Erstellen des PayPal-Auftrags.'], 500);
                }

                $orderData = $orderResponse->json();
                $orderId = $orderData['id'];
                $approveUrl = collect($orderData['links'])->firstWhere('rel', 'approve')['href'];

                // Save token and order_id
                PaypalToken::create([
                    'token' => $token,
                    'order_id' => $orderId,
                    'payload' => $validated,
                ]);

                return response()->json(['paypal_url' => $approveUrl]);
            } catch (\Exception $e) {
                Log::error('Exception during PayPal order creation', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json(['message' => 'Ein unerwarteter Fehler ist aufgetreten.'], 500);
            }
        }

        // Barzahlung
        try {
            $participant = Participant::create([
                'first_name' => $validated['firstName'],
                'last_name' => $validated['lastName'],
                'email' => $validated['email'],
                'birth_date' => $validated['birthDate'],
                'phone' => $validated['phone'],
                'training_session_id' => $validated['date'],
                'vision_test' => $visionTest,
                'passport_photos' => $passportPhotos,
            ]);

            $participant->payments()->create([
                'method' => 'cash',
                'status' => 'unpaid',
                'amount' => $amount,
                'currency' => 'EUR',
            ]);

            return response()->json(['message' => 'Anmeldung erfolgreich']);
        } catch (\Exception $e) {
            Log::error('Exception during cash booking', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Fehler bei der Anmeldung.'], 500);
        }
    }

    public function paypalComplete(Request $request)
    {
        $tokenString = $request->input('token');
        $token = PaypalToken::where('token', $tokenString)->first();

        if (!$token) {
            Log::warning('Invalid or missing PayPal token', ['token' => $tokenString]);
            return response()->json(['message' => 'Ungültiger oder fehlender Token.'], 400);
        }

        if ($token->used) {
            Log::warning('PayPal token already used', ['token' => $tokenString]);
            return response()->json(['message' => 'Token wurde bereits verwendet.'], 409);
        }

        $paypalBaseUrl = $this->getPaypalBaseUrl();

        try {
            // Get PayPal access token
            $accessTokenResponse = Http::withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.secret')
            )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

            if (!$accessTokenResponse->successful()) {
                Log::error('PayPal access token request failed in paypalComplete', [
                    'status' => $accessTokenResponse->status(),
                    'response' => $accessTokenResponse->json(),
                ]);
                return response()->json(['message' => 'Fehler bei der PayPal-Authentifizierung.'], 500);
            }

            $accessToken = $accessTokenResponse->json()['access_token'];

            // Check order status
            $orderResponse = Http::withToken($accessToken)->get($paypalBaseUrl . '/v2/checkout/orders/' . $token->order_id);

            if (!$orderResponse->successful()) {
                Log::error('PayPal order status check failed', [
                    'order_id' => $token->order_id,
                    'status' => $orderResponse->status(),
                    'response' => $orderResponse->json(),
                    'debug_id' => $orderResponse->json()['debug_id'] ?? null,
                ]);
                return response()->json(['message' => 'Fehler beim Überprüfen des Auftragsstatus.'], 500);
            }

            $orderData = $orderResponse->json();
            if ($orderData['status'] !== 'APPROVED') {
                Log::warning('PayPal order not approved', [
                    'order_id' => $token->order_id,
                    'status' => $orderData['status'],
                ]);
                return response()->json(['message' => 'Zahlung wurde nicht genehmigt.'], 400);
            }

            // Capture the payment
            $captureResponse = Http::withToken($accessToken)
                ->withHeaders(['PayPal-Request-Id' => Str::uuid()->toString()])
                ->post($paypalBaseUrl . '/v2/checkout/orders/' . $token->order_id . '/capture', []);

            if (!$captureResponse->successful()) {
                Log::error('PayPal capture failed', [
                    'order_id' => $token->order_id,
                    'status' => $captureResponse->status(),
                    'response' => $captureResponse->json(),
                    'debug_id' => $captureResponse->json()['debug_id'] ?? null,
                ]);
                return response()->json(['message' => 'Fehler beim Erfassen der Zahlung.'], 500);
            }

            $captureData = $captureResponse->json();
            if ($captureData['status'] !== 'COMPLETED') {
                Log::warning('PayPal capture not completed', [
                    'order_id' => $token->order_id,
                    'capture_status' => $captureData['status'],
                ]);
                return response()->json(['message' => 'Zahlung konnte nicht abgeschlossen werden.'], 400);
            }

            // Proceed with participant creation
            $data = $token->payload;
            $course = Course::findOrFail($data['course']);
            $visionTest = $data['visionTest'] ?? false;
            $passportPhotos = $data['passportPhotos'] ?? false;

            // Preis berechnen
            $amount = $course->base_price;
            if ($visionTest) {
                $amount += 9;
            }
            if ($passportPhotos) {
                $amount += 9;
            }
            if ($visionTest && $passportPhotos && $course->id == 1) {
                $amount = 65;
            }
            $amount = number_format($amount, 2, '.', '');

            $participant = Participant::create([
                'first_name' => $data['firstName'],
                'last_name' => $data['lastName'],
                'email' => $data['email'],
                'birth_date' => $data['birthDate'],
                'phone' => $data['phone'],
                'training_session_id' => $data['date'],
                'vision_test' => $visionTest,
                'passport_photos' => $passportPhotos,
            ]);

            $participant->payments()->create([
                'method' => 'paypal',
                'status' => 'paid',
                'amount' => $amount,
                'currency' => 'EUR',
                'transaction_id' => $captureData['purchase_units'][0]['payments']['captures'][0]['id'],
            ]);

            $token->used = true;
            $token->save();

            return response()->json(['message' => 'Teilnehmer gespeichert']);
        } catch (\Exception $e) {
            Log::error('Exception in paypalComplete', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Ein unerwarteter Fehler ist aufgetreten.'], 500);
        }
    }
}
