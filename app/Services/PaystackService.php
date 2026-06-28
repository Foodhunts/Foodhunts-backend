<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    public function initializePayment(array $payload): array
    {
        $response = Http::baseUrl(config('services.paystack.base_url'))
            ->withToken(config('services.paystack.secret'))
            ->acceptJson()
            ->post('/transaction/initialize', $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?? 'Unable to initialize Paystack payment');
        }

        return $response->json();
    }

    public function verifyPayment(string $reference): array
    {
        $response = Http::baseUrl(config('services.paystack.base_url'))
            ->withToken(config('services.paystack.secret'))
            ->acceptJson()
            ->get('/transaction/verify/'.$reference);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?? 'Unable to verify Paystack payment');
        }

        return $response->json();
    }

    public function validateWebhookSignature(Request $request): bool
    {
        $secret = (string) config('services.paystack.webhook_secret');
        $signature = (string) $request->header('x-paystack-signature');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
    }
}
