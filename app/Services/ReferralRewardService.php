<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReferralRewardService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {
    }

    public function creditRewardForOrder(Order $order): array
    {
        $order->loadMissing('user');

        if (($order->payment_status?->value ?? $order->payment_status) !== 'paid') {
            return ['status' => 'skipped', 'reason' => 'Order is not paid'];
        }

        if (! $order->user?->referred_by_user_id) {
            return ['status' => 'skipped', 'reason' => 'User has no referrer'];
        }

        $referrerId = $order->user->referred_by_user_id;
        $eligibleAmount = (float) ($order->subtotal ?: max(
            0,
            (float) $order->total_amount
            - (float) $order->delivery_fee
            - (float) ($order->tax_amount ?? 0)
            - (float) ($order->discount_amount ?? 0)
        ));

        if ($eligibleAmount <= 0) {
            return ['status' => 'skipped', 'reason' => 'No eligible amount'];
        }

        $rewardAmount = round($eligibleAmount * 0.05, 2);

        return DB::transaction(function () use ($order, $referrerId, $eligibleAmount, $rewardAmount): array {
            $existing = ReferralReward::query()
                ->where('order_id', $order->id)
                ->where('referrer_user_id', $referrerId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['status' => 'already_exists', 'reward' => $existing];
            }

            $reward = ReferralReward::create([
                'referrer_user_id' => $referrerId,
                'referred_user_id' => $order->user_id,
                'order_id' => $order->id,
                'reward_percentage' => 5.00,
                'order_amount' => $eligibleAmount,
                'reward_amount' => $rewardAmount,
                'status' => 'pending',
            ]);

            try {
                $referrer = User::query()->find($referrerId);

                if (! $referrer) {
                    throw new \RuntimeException('Referrer account not found.');
                }

                $walletResult = $this->walletService->record(
                    $referrer,
                    WalletTransactionType::ReferralBonus,
                    [
                        'amount' => $rewardAmount,
                        'reference' => 'referral_reward:'.$order->id,
                        'note' => 'Referral reward from order #'.($order->order_reference ?? $order->id),
                        'direction' => 'credit',
                        'order_id' => $order->id,
                        'metadata' => [
                            'source' => 'referral_reward',
                            'order_id' => $order->id,
                            'referred_user_id' => $order->user_id,
                            'referrer_user_id' => $referrerId,
                        ],
                    ]
                );

                $reward->update([
                    'wallet_transaction_id' => $walletResult['transaction']->id,
                    'status' => 'credited',
                    'failure_reason' => null,
                ]);
            } catch (\Throwable $throwable) {
                $reward->update([
                    'status' => 'failed',
                    'failure_reason' => $throwable->getMessage(),
                ]);
            }

            return ['status' => $reward->status, 'reward' => $reward->refresh()];
        });
    }

    public function reverseRewardForOrder(Order $order): array
    {
        $reward = ReferralReward::query()
            ->where('order_id', $order->id)
            ->where('status', 'credited')
            ->first();

        if (! $reward) {
            return ['status' => 'skipped', 'reason' => 'No credited reward found'];
        }

        return DB::transaction(function () use ($order, $reward): array {
            $locked = ReferralReward::query()
                ->whereKey($reward->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status === 'reversed') {
                return ['status' => 'already_reversed'];
            }

            try {
                $referrer = User::query()->find($locked->referrer_user_id);

                if (! $referrer) {
                    throw new \RuntimeException('Referrer account not found.');
                }

                $this->walletService->record(
                    $referrer,
                    WalletTransactionType::Reversal,
                    [
                        'amount' => $locked->reward_amount,
                        'reference' => 'referral_reward_reversal:'.$order->id,
                        'note' => 'Referral reward reversal for order #'.($order->order_reference ?? $order->id),
                        'direction' => 'debit',
                        'order_id' => $order->id,
                        'metadata' => [
                            'source' => 'referral_reward_reversal',
                            'order_id' => $order->id,
                            'referrer_user_id' => $locked->referrer_user_id,
                        ],
                    ]
                );

                $locked->update([
                    'status' => 'reversed',
                    'failure_reason' => null,
                ]);
            } catch (\Throwable $throwable) {
                $locked->update([
                    'status' => 'failed',
                    'failure_reason' => $throwable->getMessage(),
                ]);
            }

            return ['status' => $locked->status, 'reward' => $locked->refresh()];
        });
    }
}
