<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'firstName' => 'required|string',
            'lastName' => 'required|string',
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
            Log::error('Captcha-Überprüfung fehlgeschlagen', ['response' => $captchaResponse->json()]);
            return response()->json(['message' => 'Captcha-Überprüfung fehlgeschlagen.'], 422);
        }

        if ($validated['paymentMethod'] === 'onsite') {
            $course = Course::findOrFail($validated['course']);
            $visionTest = $validated['visionTest'] ?? false;
            $passportPhotos = $validated['passportPhotos'] ?? false;

            $amount = $course->base_price;
            if ($visionTest) $amount += 9;
            if ($passportPhotos) $amount += 9;
            if ($visionTest && $passportPhotos && $course->id == 1) {
                $amount = 65;
            }
            $amount = number_format($amount, 2, '.', '');

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

        // Für PayPal: Nur Formular validieren, Zahlung erfolgt im Frontend
        return response()->json(['message' => 'Formular validiert, PayPal-Zahlung kann fortgesetzt werden']);
    }

    public function paypalComplete(Request $request)
    {
        $validated = $request->validate([
            'orderID' => 'required|string',
            'payerID' => 'required|string',
            'firstName' => 'required|string',
            'lastName' => 'required|string',
            'email' => 'required|email',
            'birthDate' => 'required|date',
            'phone' => 'nullable|string',
            'course' => 'required|exists:courses,id',
            'date' => 'required|exists:training_sessions,id',
            'visionTest' => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
            'captchaToken' => 'required|string',
        ]);

        Log::info('PayPal Complete aufgerufen', ['request' => $request->all()]);

        // Turnstile prüfen
        $captchaResponse = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret'),
            'response' => $validated['captchaToken'],
            'remoteip' => $request->ip(),
        ]);

        if (!($captchaResponse->json()['success'] ?? false)) {
            Log::error('Captcha-Überprüfung fehlgeschlagen', ['response' => $captchaResponse->json()]);
            return response()->json(['message' => 'Captcha-Überprüfung fehlgeschlagen.'], 422);
        }

        // PayPal-Zahlung validieren
        $paypalBaseUrl = $this->getPaypalBaseUrl();
        $accessToken = Http::withBasicAuth(
            config('services.paypal.client_id'),
            config('services.paypal.secret')
        )->asForm()->post($paypalBaseUrl . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ])->json()['access_token'];

        $orderResponse = Http::withToken($accessToken)
            ->get($paypalBaseUrl . '/v2/checkout/orders/' . $validated['orderID']);

        if ($orderResponse->failed() || $orderResponse->json()['status'] !== 'COMPLETED') {
            Log::error('PayPal-Zahlung nicht abgeschlossen', ['response' => $orderResponse->json()]);
            return response()->json(['message' => 'Zahlung konnte nicht validiert werden.'], 400);
        }

        $capturedAmount = $orderResponse->json()['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
        $course = Course::findOrFail($validated['course']);
        $visionTest = $validated['visionTest'] ?? false;
        $passportPhotos = $validated['passportPhotos'] ?? false;

        // Preis berechnen
        $amount = $course->base_price;
        if ($visionTest) $amount += 9;
        if ($passportPhotos) $amount += 9;
        if ($visionTest && $passportPhotos && $course->id == 1) {
            $amount = 65;
        }
        $amount = number_format($amount, 2, '.', '');

        // Betrag validieren
        if ($capturedAmount != $amount) {
            Log::error('Betragsabweichung bei PayPal-Zahlung', [
                'expected' => $amount,
                'captured' => $capturedAmount,
            ]);
            return response()->json(['message' => 'Zahlungsbetrag stimmt nicht überein.'], 400);
        }

        // Teilnehmer speichern
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
            'method' => 'paypal',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'EUR',
            'paypal_order_id' => $validated['orderID'],
            'paypal_payer_id' => $validated['payerID'],
            'paypal_transaction_id' => $orderResponse->json()['purchase_units'][0]['payments']['captures'][0]['id'],
        ]);

        return response()->json(['message' => 'Zahlung und Anmeldung erfolgreich']);
    }
}