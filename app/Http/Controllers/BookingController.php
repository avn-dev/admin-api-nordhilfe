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

            $accessToken = Http::withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.secret')
            )->asForm()->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ])->json()['access_token'];

            $order = Http::withToken($accessToken)->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', [
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

            $approveUrl = collect($order->json()['links'])->firstWhere('rel', 'approve')['href'];
            return response()->json(['paypal_url' => $approveUrl]);
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
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'EUR',
        ]);

        return response()->json(['message' => 'Anmeldung erfolgreich']);
    }

    public function paypalComplete(Request $request)
    {
        $tokenString = $request->input('token');
        $token = PaypalToken::where('token', $tokenString)->first();

        if (!$token) {
            return response()->json(['message' => 'Ungültiger oder fehlender Token.'], 400);
        }

        if ($token->used) {
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
        ]);

        $token->used = true;
        $token->save();

        return response()->json(['message' => 'Teilnehmer gespeichert']);
    }
}