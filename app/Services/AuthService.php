<?php

namespace App\Services;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly ReferralService $referralService,
        private readonly FeatureFlagService $featureFlagService,
    )
    {
    }

    public function register(RegisterRequest $request): array
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'] ?? 'customer',
            ]);

            if ($this->featureFlagService->enabled('REFERRAL_CODE') && ! empty($validated['referral_code'])) {
                $this->referralService->applyReferralCode($user, $validated['referral_code']);
            }

            return $user;
        });

        return [
            'user' => new UserResource($user),
            'token' => $user->createToken('mobile')->plainTextToken,
        ];
    }

    public function login(LoginRequest $request): array
    {
        $identifier = $request->validated('login');
        $password = $request->validated('password');

        $user = User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        return [
            'user' => new UserResource($user),
            'token' => $user->createToken('mobile')->plainTextToken,
        ];
    }

    public function logout(?User $user): void
    {
        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }
    }
}
