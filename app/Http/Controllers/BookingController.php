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

    private function getPaypalAccessToken()
    {
        $paypalBaseUrl = $this->getPaypalBaseUrl();
        
        $response = Http::withBasicAuth(
            config('services.paypal.client_id'),
            config('services.paypal.secret')
        )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if (!$response->successful()) {
            throw new \Exception('Failed to get PayPal access token');
        }

        return $response->json()['access_token'];
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
            $amount += 9; // Sehtest: 9€
        }
        if ($passportPhotos) {
            $amount += 9; // Passfotos: 9€
        }
        // Rabatt für beide Optionen (3-in-1-Paket: 65€)
        if ($visionTest && $passportPhotos && $course->id == 1) {
            $amount = 65; // Rabattpreis für Erste-Hilfe-Kurs + Sehtest + Passfotos
        }
        $amount = number_format($amount, 2, '.', '');

        if ($validated['paymentMethod'] === 'paypal') {
            $token = Str::uuid()->toString();
            PaypalToken::create([
                'token' => $token,
                'payload' => $validated,
            ]);

            $returnUrl = $validated['returnUrl'] . '?payment=success&token=' . $token;

            try {
                $accessToken = $this->getPaypalAccessToken();
                $paypalBaseUrl = $this->getPaypalBaseUrl();

                $order = Http::withToken($accessToken)->post($paypalBaseUrl . '/v2/checkout/orders', [
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

                if (!$order->successful()) {
                    Log::error('PayPal order creation failed', ['response' => $order->json()]);
                    throw new \Exception('PayPal order creation failed');
                }

                $approveUrl = collect($order->json()['links'])->firstWhere('rel', 'approve')['href'];
                return response()->json(['paypal_url' => $approveUrl]);

            } catch (\Exception $e) {
                Log::error('PayPal payment error: ' . $e->getMessage());
                return response()->json(['message' => 'PayPal-Zahlung konnte nicht initiiert werden.'], 500);
            }
        }

        // Barzahlung
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
    }

    public function paypalComplete(Request $request)
    {
        // Debugging: Logge alle Parameter
        Log::info('PayPal completion request', [
            'all_params' => $request->all(),
            'query_params' => $request->query(),
            'url' => $request->fullUrl()
        ]);

        // PayPal sendet verschiedene Parameter je nach Flow
        $paypalOrderId = $request->input('token'); // PayPal Order ID (nicht unser Token!)
        $payerId = $request->input('PayerID');
        $customToken = $request->input('token'); // Unser Custom Token aus der URL
        
        // Extrahiere unseren Custom Token aus der URL
        $parsedUrl = parse_url($request->fullUrl());
        parse_str($parsedUrl['query'] ?? '', $queryParams);
        
        // Suche nach unserem Token in den Query-Parametern
        $customToken = null;
        if (isset($queryParams['token']) && strpos($queryParams['token'], '-') !== false) {
            // UUID Format erkennen
            if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $queryParams['token'])) {
                $customToken = $queryParams['token'];
            }
        }

        // Falls kein Custom Token gefunden, versuche es aus der ursprünglichen return_url zu extrahieren
        if (!$customToken) {
            // Suche in allen Query-Parametern nach einem UUID-Pattern
            foreach ($queryParams as $key => $value) {
                if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $value)) {
                    $customToken = $value;
                    break;
                }
            }
        }

        Log::info('Extracted tokens', [
            'paypal_order_id' => $paypalOrderId,
            'payer_id' => $payerId,
            'custom_token' => $customToken
        ]);

        if (!$customToken) {
            Log::error('No custom token found in PayPal return');
            return response()->json(['message' => 'Ungültiger oder fehlender Token.'], 400);
        }

        $token = PaypalToken::where('token', $customToken)->first();

        if (!$token) {
            Log::error('Custom token not found in database', ['token' => $customToken]);
            return response()->json(['message' => 'Ungültiger oder fehlender Token.'], 400);
        }

        if ($token->used) {
            Log::warning('Token already used', ['token' => $customToken]);
            return response()->json(['message' => 'Token wurde bereits verwendet.'], 409);
        }

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
            $amount = 65; // Rabattpreis
        }

        try {
            // PayPal-Zahlung erfassen
            $accessToken = $this->getPaypalAccessToken();
            $paypalBaseUrl = $this->getPaypalBaseUrl();

            // Verwende die PayPal Order ID (token Parameter) für die API-Calls
            $paypalOrderId = $request->input('token');
            
            Log::info('Attempting to capture PayPal order', ['order_id' => $paypalOrderId]);

            // Hole die Bestelldetails von PayPal
            $orderDetails = Http::withToken($accessToken)
                ->get($paypalBaseUrl . '/v2/checkout/orders/' . $paypalOrderId);

            if (!$orderDetails->successful()) {
                Log::error('Failed to get PayPal order details', [
                    'order_id' => $paypalOrderId,
                    'response' => $orderDetails->json(),
                    'status' => $orderDetails->status()
                ]);
                throw new \Exception('Failed to get PayPal order details');
            }

            $orderData = $orderDetails->json();
            
            Log::info('PayPal order details retrieved', [
                'order_id' => $orderData['id'],
                'status' => $orderData['status']
            ]);
            
            // Prüfe ob Bestellung bereits erfasst wurde
            if ($orderData['status'] === 'COMPLETED') {
                Log::info('PayPal order already completed', ['order_id' => $orderData['id']]);
            } else if ($orderData['status'] === 'APPROVED') {
                // Erfasse die Zahlung
                $captureResponse = Http::withToken($accessToken)
                    ->post($paypalBaseUrl . '/v2/checkout/orders/' . $paypalOrderId . '/capture');

                if (!$captureResponse->successful()) {
                    Log::error('PayPal capture failed', [
                        'order_id' => $paypalOrderId,
                        'response' => $captureResponse->json(),
                        'status' => $captureResponse->status()
                    ]);
                    throw new \Exception('PayPal capture failed');
                }

                $captureData = $captureResponse->json();
                
                Log::info('PayPal capture response', [
                    'order_id' => $captureData['id'],
                    'status' => $captureData['status']
                ]);
                
                // Prüfe ob Zahlung erfolgreich war
                if ($captureData['status'] !== 'COMPLETED') {
                    Log::error('PayPal capture not completed', ['status' => $captureData['status']]);
                    throw new \Exception('PayPal payment not completed');
                }

                Log::info('PayPal payment captured successfully', ['order_id' => $captureData['id']]);
            } else {
                Log::error('PayPal order in unexpected status', [
                    'order_id' => $orderData['id'],
                    'status' => $orderData['status']
                ]);
                throw new \Exception('PayPal order not approved');
            }

            // Erstelle Teilnehmer nur wenn Zahlung erfolgreich
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
                'transaction_id' => $orderData['id'], // Speichere PayPal Order ID
            ]);

            $token->used = true;
            $token->save();

            Log::info('Participant created successfully', [
                'participant_id' => $participant->id,
                'paypal_order_id' => $orderData['id']
            ]);

            return response()->json(['message' => 'Teilnehmer gespeichert']);

        } catch (\Exception $e) {
            Log::error('PayPal completion error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'custom_token' => $customToken,
                'paypal_order_id' => $paypalOrderId
            ]);
            return response()->json(['message' => 'PayPal-Zahlung konnte nicht abgeschlossen werden.'], 500);
        }
    }
}