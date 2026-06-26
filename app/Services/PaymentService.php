<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function initialize($user, array $data): array
    {
        $order = Order::query()->findOrFail($data['order_id']);

        $attempt = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('provider', 'paystack')
            ->where('status', PaymentStatus::Pending->value)
            ->latest()
            ->first();

        if (! $attempt) {
            $attempt = PaymentAttempt::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'provider' => 'paystack',
                'reference' => (string) Str::uuid(),
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'NGN',
                'status' => PaymentStatus::Pending->value,
            ]);
        }

        return [
            'reference' => $attempt->reference,
            'payment_attempt' => $attempt,
        ];
    }

    public function verify(array $data): array
    {
        $attempt = PaymentAttempt::query()->where('reference', $data['reference'])->firstOrFail();

        if ($attempt->verified_at) {
            return ['status' => 'already_verified', 'payment_attempt' => $attempt];
        }

        $attempt->update([
            'status' => PaymentStatus::Paid->value,
            'verified_at' => now(),
        ]);

        if ($attempt->order_id) {
            Order::query()
                ->whereKey($attempt->order_id)
                ->where('payment_status', '!=', PaymentStatus::Paid->value)
                ->update([
                    'payment_status' => PaymentStatus::Paid->value,
                    'payment_reference' => $attempt->reference,
                ]);
        }

        return ['status' => 'verified', 'payment_attempt' => $attempt->refresh()];
    }

    public function handleWebhook(Request $request): array
    {
        $secret = config('services.paystack.webhook_secret');
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();

        if (! hash_equals(hash_hmac('sha512', $payload, $secret), (string) $signature)) {
            abort(401, 'Invalid webhook signature');
        }

        $event = $request->json()->all();

        Log::info('Paystack webhook received', ['event' => $event['event'] ?? null]);

        return ['status' => 'ok'];
    }

    public function createPaystackAuthorizationUrl(Order $order, User $user): array
    {
        $response = Http::baseUrl(config('services.paystack.base_url'))
            ->withToken(config('services.paystack.secret'))
            ->post('/transaction/initialize', [
                'email' => $user->email ?? $user->phone.'@foodhunts.local',
                'amount' => (int) round($order->total_amount * 100),
                'reference' => (string) Str::uuid(),
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                ],
            ]);

        return $response->json();
    }
}
