<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\BuyForMeRequest;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaystackService $paystackService,
        private readonly ReferralRewardService $referralRewardService,
        private readonly BuyForMeService $buyForMeService,
    ) {
    }

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
                'amount' => $order->total_amount,
                'currency' => $order->currency ?? ($data['currency'] ?? 'NGN'),
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
        $reference = (string) $data['reference'];
        $attempt = PaymentAttempt::query()->where('reference', $reference)->firstOrFail();

        if ($attempt->verified_at || $attempt->status === PaymentStatus::Paid->value) {
            return ['status' => 'already_verified', 'payment_attempt' => $attempt];
        }

        $gatewayResponse = $this->paystackService->verifyPayment($reference);
        $gatewayData = $gatewayResponse['data'] ?? [];

        if (($gatewayData['status'] ?? null) !== 'success') {
            $attempt->update([
                'status' => PaymentStatus::Failed->value,
                'gateway_response' => $gatewayResponse,
            ]);

            return ['status' => 'failed', 'payment_attempt' => $attempt->refresh()];
        }

        return DB::transaction(function () use ($attempt, $gatewayResponse, $gatewayData, $reference): array {
            $attempt->refresh();

            if ($attempt->verified_at || $attempt->status === PaymentStatus::Paid->value) {
                return ['status' => 'already_verified', 'payment_attempt' => $attempt];
            }

            if ($attempt->order_id) {
                $attempt->update([
                    'status' => PaymentStatus::Paid->value,
                    'verified_at' => now(),
                    'gateway_response' => $gatewayResponse,
                ]);

                $order = Order::query()->with('user')->findOrFail($attempt->order_id);

                $order->update([
                    'payment_status' => PaymentStatus::Paid->value,
                    'payment_reference' => $reference,
                ]);

                $this->referralRewardService->creditRewardForOrder($order);

                return [
                    'status' => 'verified',
                    'payment_attempt' => $attempt->refresh(),
                    'order_id' => $order->id,
                ];
            }

            $buyForMeRequest = BuyForMeRequest::query()
                ->where('payment_reference', $reference)
                ->first();

            if ($buyForMeRequest) {
                $finalizeResult = $this->buyForMeService->finalizePayment($buyForMeRequest, $gatewayData);

                if (($finalizeResult['status'] ?? null) === 'skipped') {
                    $attempt->update([
                        'status' => PaymentStatus::Failed->value,
                        'gateway_response' => $gatewayResponse,
                    ]);
                }

                return [
                    'status' => $finalizeResult['status'] ?? 'verified',
                    'payment_attempt' => $attempt->refresh(),
                    'order_id' => $finalizeResult['order_id'] ?? null,
                ];
            }

            $attempt->update([
                'status' => PaymentStatus::Paid->value,
                'verified_at' => now(),
                'gateway_response' => $gatewayResponse,
            ]);

            return ['status' => 'verified', 'payment_attempt' => $attempt->refresh()];
        });
    }

    public function handleWebhook(Request $request): array
    {
        if (! $this->paystackService->validateWebhookSignature($request)) {
            abort(401, 'Invalid webhook signature');
        }

        $event = $request->json()->all();
        Log::info('Paystack webhook received', ['event' => $event['event'] ?? null]);

        if (($event['event'] ?? null) !== 'charge.success') {
            return ['status' => 'ignored'];
        }

        $gatewayData = $event['data'] ?? [];
        $reference = (string) ($gatewayData['reference'] ?? '');

        if ($reference === '') {
            return ['status' => 'ignored'];
        }

        $attempt = PaymentAttempt::query()->where('reference', $reference)->first();

        if ($attempt && ($attempt->verified_at || $attempt->status === PaymentStatus::Paid->value)) {
            return ['status' => 'already_processed'];
        }

        if ($attempt && data_get($attempt->raw_payload, 'source') === 'buy_for_me') {
            $requestId = data_get($attempt->raw_payload, 'buy_for_me_request_id');

            if ($requestId) {
                $buyForMeRequest = BuyForMeRequest::query()->find($requestId);

                if ($buyForMeRequest) {
                    $finalizeResult = $this->buyForMeService->finalizePayment($buyForMeRequest, $gatewayData);

                    if (($finalizeResult['status'] ?? null) === 'skipped') {
                        $attempt->update([
                            'status' => PaymentStatus::Failed->value,
                            'gateway_response' => $event,
                        ]);
                    }

                    return ['status' => 'ok'];
                }
            }
        }

        if ($attempt?->order_id) {
            $order = Order::query()->with('user')->find($attempt->order_id);

            if ($order) {
                $order->update(['payment_status' => PaymentStatus::Paid->value, 'payment_reference' => $reference]);
                $attempt->update([
                    'status' => PaymentStatus::Paid->value,
                    'verified_at' => now(),
                    'gateway_response' => $event,
                ]);
                $this->referralRewardService->creditRewardForOrder($order);
            }

            return ['status' => 'ok'];
        }

        $buyForMeRequest = BuyForMeRequest::query()->where('payment_reference', $reference)->first();
        if ($buyForMeRequest) {
            $this->buyForMeService->finalizePayment($buyForMeRequest, $gatewayData);
        }

        return ['status' => 'ok'];
    }

    public function createPaystackAuthorizationUrl(Order $order, User $user): array
    {
        return $this->paystackService->initializePayment([
            'email' => $user->email ?? $user->phone.'@foodhunts.local',
            'amount' => (int) round($order->total_amount * 100),
            'reference' => (string) Str::uuid(),
            'metadata' => [
                'order_id' => $order->id,
                'user_id' => $user->id,
            ],
        ]);
    }
}
