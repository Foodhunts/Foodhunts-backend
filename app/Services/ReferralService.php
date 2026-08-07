<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralService
{
    public function __construct(
        private readonly ReferralCodeService $referralCodeService,
    ) {
    }

    public function applyReferralCode(User $user, string $referralCode): Referral
    {
        $code = strtoupper(trim($referralCode));

        if ($code === '') {
            throw ValidationException::withMessages([
                'referral_code' => ['Referral code is required.'],
            ]);
        }

        if ($user->referred_by_user_id) {
            throw ValidationException::withMessages([
                'referral_code' => ['A referral code has already been applied to this account.'],
            ]);
        }

        $referrer = User::query()->where('referral_code', $code)->first();

        if (! $referrer) {
            throw ValidationException::withMessages([
                'referral_code' => ['Invalid referral code.'],
            ]);
        }

        if ($referrer->id === $user->id) {
            throw ValidationException::withMessages([
                'referral_code' => ['Self-referral is not allowed.'],
            ]);
        }

        return DB::transaction(function () use ($user, $referrer, $code): Referral {
            $user->forceFill([
                'referred_by_user_id' => $referrer->id,
                'referred_at' => now(),
            ])->save();

            return Referral::query()->updateOrCreate(
                ['referred_user_id' => $user->id],
                [
                    'referrer_user_id' => $referrer->id,
                    'referral_code_used' => $code,
                    'status' => 'active',
                ]
            );
        });
    }

    public function buildReferralSummary(User $user): array
    {
        $dashboard = $this->getReferralDashboard($user);

        return [
            'referral_code' => $dashboard['referral_code'],
            'total_referred_users' => $dashboard['total_referred_users'],
            'total_referral_earnings' => $dashboard['total_referral_earnings'],
            'invited_users' => $dashboard['invited_users'],
            'reward_history' => $dashboard['reward_history'],
            'referral_relationships' => $dashboard['invited_users'],
            'referral_reward_history' => $dashboard['reward_history'],
        ];
    }

    public function getReferralDashboard(User $user): array
    {
        if (! $user->referral_code) {
            $user->forceFill(['referral_code' => $this->referralCodeService->generate()])->save();
        }

        $referralCode = $user->referral_code;

        $referrals = Referral::query()
            ->where('referrer_user_id', $user->id)
            ->latest()
            ->get();

        $referredUserIds = $referrals->pluck('referred_user_id')->filter()->values()->all();

        $referredUsers = User::query()
            ->whereIn('id', $referredUserIds)
            ->get()
            ->keyBy('id');

        $orderStats = Order::query()
            ->select([
                'user_id',
                DB::raw('count(*) as total_orders'),
                DB::raw('max(created_at) as last_order_at'),
            ])
            ->whereIn('user_id', $referredUserIds)
            ->where('payment_status', 'paid')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $rewardTotals = ReferralReward::query()
            ->select([
                'referred_user_id',
                DB::raw('coalesce(sum(reward_amount), 0) as total_reward_amount'),
            ])
            ->where('referrer_user_id', $user->id)
            ->where('status', 'credited')
            ->groupBy('referred_user_id')
            ->get()
            ->keyBy('referred_user_id');

        $rewards = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->latest()
            ->get();

        $invitedUsers = collect($referredUserIds)->map(function (string $referredUserId) use ($referredUsers, $orderStats, $rewardTotals): array {
            $referredUser = $referredUsers->get($referredUserId);
            $orderStat = $orderStats->get($referredUserId);
            $rewardTotal = $rewardTotals->get($referredUserId);

            $name = trim(implode(' ', array_filter([
                $referredUser?->first_name,
                $referredUser?->last_name,
            ])));
            $displayName = $name !== '' ? $name : ($referredUser?->name ?: 'Foodhunts user');

            return [
                'id' => $referredUserId,
                'name' => $displayName,
                'email' => $this->maskEmail($referredUser?->email),
                'phone' => $this->maskPhone($referredUser?->phone),
                'joined_at' => $referredUser?->created_at,
                'total_orders' => (int) ($orderStat->total_orders ?? 0),
                'total_rewards_earned_from_user' => (float) ($rewardTotal->total_reward_amount ?? 0),
                'last_order_at' => $orderStat?->last_order_at,
            ];
        })->values()->all();

        return [
            'referral_code' => $referralCode,
            'total_referred_users' => $referrals->count(),
            'total_referral_earnings' => (float) $rewards
                ->where('status', 'credited')
                ->sum('reward_amount'),
            'invited_users' => $invitedUsers,
            'reward_history' => $rewards->map(fn (ReferralReward $reward) => [
                'id' => $reward->id,
                'order_id' => $reward->order_id,
                'reward_percentage' => $reward->reward_percentage,
                'order_amount' => $reward->order_amount,
                'reward_amount' => $reward->reward_amount,
                'status' => $reward->status,
                'failure_reason' => $reward->failure_reason,
                'created_at' => $reward->created_at,
            ])->values()->all(),
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        if ($local === '') {
            return $email;
        }

        $visible = substr($local, 0, 2);
        $visible = $visible === false ? '' : $visible;

        return $visible.'***@'.$domain;
    }

    private function maskPhone(?string $phone): ?string
    {
        if (! $phone) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if (! $digits) {
            return $phone;
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 3).str_repeat('*', max(0, strlen($digits) - 7)).substr($digits, -4);
    }
}
