<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    public function baseUrl(): string
    {
        return config('services.paypal.mode') === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public function accessToken(): string
    {
        return Cache::remember('paypal_access_token', 50 * 60, function () {
            $res = Http::asForm()
                ->withBasicAuth(config('services.paypal.client_id'), config('services.paypal.secret'))
                ->post($this->baseUrl().'/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if ($res->failed()) {
                Log::error('PayPal OAuth failed', ['body' => $res->json()]);
                abort(502, 'PayPal-Authentifizierung fehlgeschlagen');
            }
            return $res->json('access_token');
        });
    }

    public function createOrder(string $amount, string $description, ?string $requestId = null): array
    {
        $headers = [
            'Prefer'            => 'return=representation',
            'PayPal-Request-Id' => $requestId ?: (string) Str::uuid(), // Idempotenz
        ];

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => $amount,
                ],
                'description' => $description,
            ]],
            'application_context' => [
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'PAY_NOW',
                'brand_name'          => config('app.name'),
                'locale'              => 'de-DE',
            ],
        ];

        $res = Http::withToken($this->accessToken())
            ->withHeaders($headers)
            ->post($this->baseUrl().'/v2/checkout/orders', $payload);

        if ($res->failed()) {
            Log::error('PayPal create order failed', ['response' => $res->json()]);
            abort(502, 'PayPal-Order konnte nicht erstellt werden');
        }

        return $res->json();
    }

    public function captureOrder(string $orderId, ?string $requestId = null): array
    {
        $res = Http::withToken($this->accessToken())
            ->withHeaders([
                'Prefer'            => 'return=representation',
                'PayPal-Request-Id' => $requestId ?: (string) Str::uuid(),
            ])
            ->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture");

        if ($res->failed()) {
            Log::error('PayPal capture failed', ['orderId' => $orderId, 'response' => $res->json()]);
            abort(400, 'Zahlung konnte nicht abgeschlossen werden');
        }

        return $res->json();
    }
}
