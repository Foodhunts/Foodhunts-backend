<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\ReferralReward;
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
        if (! $user->referral_code) {
            $user->forceFill(['referral_code' => $this->referralCodeService->generate()])->save();
        }

        $referralCode = $user->referral_code;

        $rewards = ReferralReward::query()
            ->where('referrer_user_id', $user->id)
            ->latest()
            ->get();

        return [
            'referral_code' => $referralCode,
            'total_referred_users' => Referral::query()
                ->where('referrer_user_id', $user->id)
                ->count(),
            'total_referral_earnings' => (float) $rewards
                ->where('status', 'credited')
                ->sum('reward_amount'),
            'referral_relationships' => Referral::query()
                ->where('referrer_user_id', $user->id)
                ->with(['referred:id,first_name,last_name,email,phone,referral_code,created_at'])
                ->latest()
                ->get()
                ->map(fn (Referral $referral) => [
                    'id' => $referral->id,
                    'referral_code_used' => $referral->referral_code_used,
                    'status' => $referral->status,
                    'created_at' => $referral->created_at,
                    'referred_user' => $referral->referred,
                ]),
            'referral_reward_history' => $rewards->map(fn (ReferralReward $reward) => [
                'id' => $reward->id,
                'order_id' => $reward->order_id,
                'reward_percentage' => $reward->reward_percentage,
                'order_amount' => $reward->order_amount,
                'reward_amount' => $reward->reward_amount,
                'status' => $reward->status,
                'failure_reason' => $reward->failure_reason,
                'created_at' => $reward->created_at,
            ]),
        ];
    }
}
