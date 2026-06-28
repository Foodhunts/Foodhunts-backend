<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\BuyForMeRequest;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuyForMeService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaystackService $paystackService,
        private readonly ReferralRewardService $referralRewardService,
        private readonly FeatureFlagService $featureFlagService,
    ) {
    }

    public function createRequest(User $user, array $data): BuyForMeRequest
    {
        if (! $this->featureFlagService->enabled('BUY_FOR_ME')) {
            throw ValidationException::withMessages([
                'buy_for_me' => ['Buy For Me is currently disabled.'],
            ]);
        }

        $quote = $this->orderService->quoteCart($data['restaurant_id'], $data['items']);
        $address = $this->orderService->resolveAddressForUser($user, $data['delivery_address_id']);
        $deliveryFee = $quote['delivery_fee'];
        $serviceFee = $quote['service_fee'];
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $totalAmount = round(max(0, $quote['subtotal'] + $deliveryFee + $serviceFee - $discountAmount), 2);

        return BuyForMeRequest::create([
            'token' => $this->generateToken(),
            'requester_user_id' => $user->id,
            'restaurant_id' => $data['restaurant_id'],
            'cart_snapshot' => $quote['items'],
            'delivery_address_snapshot' => [
                'id' => $address->id,
                'label' => $address->label,
                'street_address' => $address->street_address,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->postal_code,
                'country' => $address->country,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ],
            'delivery_address_id' => $address->id,
            'subtotal' => $quote['subtotal'],
            'delivery_fee' => $deliveryFee,
            'service_fee' => $serviceFee,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'currency' => 'NGN',
            'message' => $data['message'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);
    }

    public function publicSummary(BuyForMeRequest $request): array
    {
        return [
            'token' => $request->token,
            'restaurant' => $request->restaurant ? [
                'id' => $request->restaurant->id,
                'name' => $request->restaurant->name,
                'logo_url' => $request->restaurant->logo_url,
                'city' => null,
                'state' => null,
            ] : null,
            'items' => collect($request->cart_snapshot)->map(fn (array $item) => [
                'name' => $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'price' => $item['unit_price'] ?? $item['price'] ?? null,
                'image_url' => $item['image_url'] ?? null,
            ])->values()->all(),
            'delivery_address' => $request->delivery_address_snapshot,
            'delivery_fee' => $request->delivery_fee,
            'service_fee' => $request->service_fee,
            'subtotal' => $request->subtotal,
            'discount_amount' => $request->discount_amount,
            'total_amount' => $request->total_amount,
            'currency' => $request->currency,
            'message' => $request->message,
            'expires_at' => $request->expires_at,
            'status' => $request->status,
            'order_id' => $request->order_id,
        ];
    }

    public function initiatePublicPayment(BuyForMeRequest $request, array $payerData): array
    {
        if (! $this->featureFlagService->enabled('BUY_FOR_ME')) {
            throw ValidationException::withMessages([
                'buy_for_me' => ['Buy For Me is currently disabled.'],
            ]);
        }

        if ($request->expires_at && $request->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => ['This link has expired.']]);
        }

        if (in_array($request->status, ['cancelled', 'expired', 'order_created'], true)) {
            throw ValidationException::withMessages(['token' => ['This link cannot be paid.']]);
        }

        $request->forceFill([
            'payer_name' => $payerData['payer_name'],
            'payer_email' => $payerData['payer_email'],
            'payer_phone' => $payerData['payer_phone'] ?? null,
        ])->save();

        $reference = $request->payment_reference ?: 'bfm_'.Str::uuid()->toString();

        $gatewayResponse = $this->paystackService->initializePayment([
            'email' => $payerData['payer_email'],
            'amount' => (int) round(((float) $request->total_amount) * 100),
            'reference' => $reference,
            'callback_url' => rtrim(config('foodhunts.frontend_url'), '/').'/buy-for-me/'.$request->token,
            'metadata' => [
                'source' => 'buy_for_me',
                'buy_for_me_request_id' => $request->id,
                'token' => $request->token,
                'requester_user_id' => $request->requester_user_id,
                'restaurant_id' => $request->restaurant_id,
                'payer_name' => $payerData['payer_name'],
                'payer_email' => $payerData['payer_email'],
                'payer_phone' => $payerData['payer_phone'] ?? null,
            ],
        ]);

        $request->update([
            'payment_reference' => $reference,
            'payment_provider' => 'paystack',
            'status' => 'payment_initialized',
        ]);

        PaymentAttempt::updateOrCreate(
            ['reference' => $reference],
            [
                'user_id' => $request->requester_user_id,
                'order_id' => null,
                'provider' => 'paystack',
                'amount' => $request->total_amount,
                'currency' => $request->currency,
                'status' => PaymentStatus::Pending->value,
                'raw_payload' => [
                    'source' => 'buy_for_me',
                    'buy_for_me_request_id' => $request->id,
                    'token' => $request->token,
                ],
            ]
        );

        return [
            'authorization_url' => data_get($gatewayResponse, 'data.authorization_url'),
            'reference' => $reference,
            'status' => 'payment_initialized',
        ];
    }

    public function finalizePayment(BuyForMeRequest $request, array $gatewayData): array
    {
        if ($request->order_id && $request->status === 'order_created') {
            return ['status' => 'already_processed', 'order_id' => $request->order_id];
        }

        if (in_array($request->status, ['cancelled', 'expired'], true)) {
            return ['status' => 'skipped', 'reason' => 'Request is no longer active'];
        }

        try {
            $result = DB::transaction(function () use ($request, $gatewayData): array {
                $request = BuyForMeRequest::query()
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($request->order_id && $request->status === 'order_created') {
                    return ['status' => 'already_processed', 'order_id' => $request->order_id];
                }

                if (in_array($request->status, ['cancelled', 'expired'], true)) {
                    return ['status' => 'skipped', 'reason' => 'Request is no longer active'];
                }

                $order = $this->orderService->createOrderFromCart(
                    User::query()->findOrFail($request->requester_user_id),
                    [
                        'restaurant_id' => $request->restaurant_id,
                        'delivery_address_id' => $request->delivery_address_id,
                        'items' => collect($request->cart_snapshot)->map(fn (array $item) => [
                            'menu_item_id' => $item['menu_item_id'] ?? null,
                            'quantity' => (int) ($item['quantity'] ?? 1),
                        ])->all(),
                        'payment_reference' => $request->payment_reference,
                        'payment_provider' => 'paystack',
                        'currency' => $request->currency,
                        'discount_amount' => $request->discount_amount,
                        'metadata' => [
                            'source' => 'buy_for_me',
                            'buy_for_me_request_id' => $request->id,
                            'token' => $request->token,
                            'payer_name' => $request->payer_name,
                            'payer_email' => $request->payer_email,
                            'payer_phone' => $request->payer_phone,
                            'paystack_payload' => $gatewayData,
                        ],
                        'subtotal' => $request->subtotal,
                        'delivery_fee' => $request->delivery_fee,
                        'service_charge' => $request->service_fee,
                        'payment_status' => PaymentStatus::Paid->value,
                        'status' => 'pending',
                    ]
                );

                $this->referralRewardService->creditRewardForOrder($order);

                $request->update([
                    'status' => 'order_created',
                    'order_id' => $order->id,
                    'paid_at' => now(),
                    'failure_reason' => null,
                ]);

                PaymentAttempt::query()
                    ->where('reference', $request->payment_reference)
                    ->update([
                        'order_id' => $order->id,
                        'status' => PaymentStatus::Paid->value,
                        'gateway_response' => $gatewayData,
                        'verified_at' => now(),
                    ]);

                return ['status' => 'created', 'order_id' => $order->id];
            });

            return $result;
        } catch (\Throwable $throwable) {
            if ($request->payment_reference) {
                $request->update([
                    'status' => 'failed',
                    'failure_reason' => $throwable->getMessage(),
                ]);
            }

            return [
                'status' => 'failed',
                'reason' => $throwable->getMessage(),
            ];
        }
    }

    public function cancel(BuyForMeRequest $request): BuyForMeRequest
    {
        if (! in_array($request->status, ['pending', 'payment_initialized'], true)) {
            throw ValidationException::withMessages([
                'token' => ['This request cannot be cancelled.'],
            ]);
        }

        $request->update(['status' => 'cancelled']);

        return $request->refresh();
    }

    public function findByToken(string $token): BuyForMeRequest
    {
        return BuyForMeRequest::query()->where('token', $token)->firstOrFail();
    }

    public function findByIdForUser(User $user, string $id): BuyForMeRequest
    {
        return BuyForMeRequest::query()
            ->whereKey($id)
            ->where('requester_user_id', $user->id)
            ->firstOrFail();
    }

    private function generateToken(): string
    {
        do {
            $token = Str::random(48);
        } while (BuyForMeRequest::query()->where('token', $token)->exists());

        return $token;
    }
}
