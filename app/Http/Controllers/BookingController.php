<?php

namespace App\Http\Controllers;

use App\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Course;
use App\Models\PaypalToken;
use Illuminate\Support\Str;


class BookingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'firstName' => 'required',
            'lastName' => 'required',
            'email' => 'required|email',
            'birthDate' => 'required|date',
            'phone' => 'nullable|string',
            'course' => 'required|exists:courses,id', // passt jetzt zum Frontend
            'date' => 'required|exists:training_sessions,id',
            'paymentMethod' => 'required|in:paypal,onsite',
            'captchaToken' => 'required|string',
            'returnUrl' => 'required|url',
            // ↓ optional/boolean erlauben
            'option_eye_test' => 'nullable|boolean',
            'option_photos' => 'nullable|boolean',
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
        $baseAmount    = (float) $course->base_price;
        $eyeTestPrice  = 6.99;
        $photoPrice    = 9.99;
        $discount      = 2.00;

        $eyeTest = $request->boolean('option_eye_test');
        $photos  = $request->boolean('option_photos');

        $total = $baseAmount + ($eyeTest ? $eyeTestPrice : 0) + ($photos ? $photoPrice : 0);
        if ($eyeTest && $photos) {
            $total -= $discount;
        }

        $amount = number_format($total, 2, '.', '');

        if ($validated['paymentMethod'] === 'paypal') {
            $token = Str::uuid()->toString();
            PaypalToken::create([
                'token' => $token,
                'payload' => array_merge($validated, [
                    'option_eye_test' => $eyeTest,
                    'option_photos' => $photos,
                    'calculated_amount' => $amount, // zur Sicherheit mitschicken
                ]),
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
                        ($eyeTest ? ' + Sehtest' : '') .
                        ($photos ? ' + Fotos' : '')
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
            'option_eye_test' => $eyeTest,
            'option_photos' => $photos,
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

        $course = Course::findOrFail($data['course'] ?? $data['course_id'] ?? null);

        // Optionen & Betrag wie im store() rekonstruieren (oder calculated_amount aus Token nutzen)
        $eyeTest = (bool) ($data['option_eye_test'] ?? false);
        $photos  = (bool) ($data['option_photos'] ?? false);

        $baseAmount    = (float) $course->base_price;
        $eyeTestPrice  = 6.99;
        $photoPrice    = 9.99;
        $discount      = 2.00;

        $total = $baseAmount + ($eyeTest ? $eyeTestPrice : 0) + ($photos ? $photoPrice : 0);
        if ($eyeTest && $photos) {
            $total -= $discount;
        }
        $amount = number_format($total, 2, '.', '');

        $participant = Participant::create([
            'first_name' => $data['firstName'],
            'last_name'  => $data['lastName'],
            'email'      => $data['email'],
            'birth_date' => $data['birthDate'],
            'phone'      => $data['phone'] ?? null,
            'training_session_id' => $data['date'],
            'option_eye_test' => $eyeTest,
            'option_photos'  => $photos,
        ]);

        $participant->payments()->create([
            'method' => 'paypal',
            'status' => 'paid',
            'amount' => $amount,   // ← nicht base_price!
            'currency' => 'EUR',
        ]);

        $token->used = true;
        $token->save();

        return response()->json(['message' => 'Teilnehmer gespeichert']);
    }

    /**
     * Display the specified resource.
     */
    public function show(Participant $participant)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Participant $participant)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Participant $participant)
    {
        //
    }
}
