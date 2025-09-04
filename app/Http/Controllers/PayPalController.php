<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Services\PayPalService;

class PayPalController extends Controller
{
    public function __construct(private PayPalService $paypal) {}

    public function config()
    {
        return response()->json([
            'clientId' => config('services.paypal.client_id'),
            'mode'     => config('services.paypal.mode'),
        ]);
    }

    private function verifyTurnstile(string $token, string $ip): void
    {
        $res = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret'   => config('services.turnstile.secret'),
            'response' => $token,
            'remoteip' => $ip,
        ]);
        if (!($res->json('success') ?? false)) {
            Log::warning('Turnstile failed', ['resp' => $res->json()]);
            throw ValidationException::withMessages([
                'captchaToken' => 'Captcha-Überprüfung fehlgeschlagen.',
            ]);
        }
    }

    /** Einheitliche Preislogik (Server is source of truth) */
    private function calculateAmount(int $courseId, bool $vision, bool $photos): string
    {
        $course = Course::findOrFail($courseId);

        $amount = (float) $course->base_price;
        if ($courseId === 1) {
            if ($vision) $amount += 9;
            if ($photos) $amount += 9;
            if ($vision && $photos) $amount = 65; // 3-in-1-Paket
        }
        return number_format($amount, 2, '.', '');
    }

    /** POST /api/paypal/order */
    public function createOrder(Request $req)
    {
        $validated = $req->validate([
            'firstName'      => 'required|string',
            'lastName'       => 'required|string',
            'email'          => 'required|email',
            'birthDate'      => 'required|date',
            'phone'          => 'nullable|string',
            'course'         => 'required|exists:courses,id',
            'date'           => 'required|exists:training_sessions,id',
            'visionTest'     => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
            'captchaToken'   => 'required|string',
        ]);

        $this->verifyTurnstile($validated['captchaToken'], $req->ip());

        // (Optional) Kapazitäten prüfen…
        // …

        $amount = $this->calculateAmount(
            (int) $validated['course'],
            (bool) ($validated['visionTest'] ?? false),
            (bool) ($validated['passportPhotos'] ?? false)
        );

        $description = 'Kursanmeldung: Kurs '.$validated['course'].'; Termin '.$validated['date'].
            (($validated['visionTest'] ?? false) ? ', Sehtest' : '').
            (($validated['passportPhotos'] ?? false) ? ', Passfotos' : '');

        $order = $this->paypal->createOrder($amount, $description);

        return response()->json([
            'orderID'  => $order['id'],
            'status'   => $order['status'], // CREATED
            'amount'   => $amount,
            'currency' => 'EUR',
        ]);
    }

    /** POST /api/paypal/capture */
    public function capture(Request $req)
    {
        $validated = $req->validate([
            'orderID'        => 'required|string',
            'firstName'      => 'required|string',
            'lastName'       => 'required|string',
            'email'          => 'required|email',
            'birthDate'      => 'required|date',
            'phone'          => 'nullable|string',
            'course'         => 'required|exists:courses,id',
            'date'           => 'required|exists:training_sessions,id',
            'visionTest'     => 'nullable|boolean',
            'passportPhotos' => 'nullable|boolean',
            'captchaToken'   => 'required|string',
        ]);

        $this->verifyTurnstile($validated['captchaToken'], $req->ip());

        $expectedAmount = $this->calculateAmount(
            (int) $validated['course'],
            (bool) ($validated['visionTest'] ?? false),
            (bool) ($validated['passportPhotos'] ?? false)
        );

        // Capture auf dem Server
        $capture = $this->paypal->captureOrder($validated['orderID']);

        if (($capture['status'] ?? '') !== 'COMPLETED') {
            Log::error('PayPal not completed', ['capture' => $capture]);
            return response()->json(['message' => 'Zahlung nicht abgeschlossen.'], 400);
        }

        $cap = $capture['purchase_units'][0]['payments']['captures'][0] ?? null;
        if (!$cap) {
            Log::error('Missing capture node', ['capture' => $capture]);
            return response()->json(['message' => 'Unerwartete PayPal-Antwort.'], 400);
        }

        $capturedAmount = $cap['amount']['value'] ?? null;
        $currency       = $cap['amount']['currency_code'] ?? 'EUR';

        if ($currency !== 'EUR' || $capturedAmount !== $expectedAmount) {
            Log::error('Amount mismatch', [
                'expected' => $expectedAmount,
                'captured' => $capturedAmount,
                'currency' => $currency,
            ]);
            return response()->json(['message' => 'Zahlungsbetrag stimmt nicht überein.'], 400);
        }

        // Persistieren – transaktional
        DB::transaction(function () use ($validated, $expectedAmount, $currency, $cap) {
            $participant = Participant::create([
                'first_name'         => $validated['firstName'],
                'last_name'          => $validated['lastName'],
                'email'              => $validated['email'],
                'birth_date'         => $validated['birthDate'],
                'phone'              => $validated['phone'],
                'training_session_id'=> $validated['date'],
                'vision_test'        => (bool) ($validated['visionTest'] ?? false),
                'passport_photos'    => (bool) ($validated['passportPhotos'] ?? false),
            ]);

            $participant->payments()->create([
                'method'                 => 'paypal',
                'status'                 => 'paid',
                'amount'                 => $expectedAmount,
                'currency'               => $currency,
                'paypal_order_id'        => $cap['supplementary_data']['related_ids']['order_id'] ?? null,
                'paypal_transaction_id'  => $cap['id'] ?? null,
            ]);
        });

        return response()->json(['message' => 'Zahlung und Anmeldung erfolgreich']);
    }

    /** Optional: Webhook-Handler (PAYMENT.CAPTURE.COMPLETED) */
    public function webhook(Request $req)
    {
        // Signatur prüfen (verify-webhook-signature)
        $webhookId = config('services.paypal.webhook_id');
        if (!$webhookId) return response()->noContent();

        $verification = Http::withToken($this->paypal->accessToken())
            ->post($this->paypal->baseUrl().'/v1/notifications/verify-webhook-signature', [
                'auth_algo'         => $req->header('PAYPAL-AUTH-ALGO'),
                'cert_url'          => $req->header('PAYPAL-CERT-URL'),
                'transmission_id'   => $req->header('PAYPAL-TRANSMISSION-ID'),
                'transmission_sig'  => $req->header('PAYPAL-TRANSMISSION-SIG'),
                'transmission_time' => $req->header('PAYPAL-TRANSMISSION-TIME'),
                'webhook_id'        => $webhookId,
                'webhook_event'     => $req->json(),
            ]);

        if (($verification->json('verification_status') ?? '') !== 'SUCCESS') {
            Log::warning('PayPal webhook verification failed', ['body' => $verification->json()]);
            return response()->noContent(400);
        }

        // hier Events (z.B. PAYMENT.CAPTURE.COMPLETED) auswerten …
        return response()->noContent();
    }
}
