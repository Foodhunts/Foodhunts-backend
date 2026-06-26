<?php

namespace App\Services;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function register(RegisterRequest $request): array
    {
        $user = User::create([
            ...$request->validated(),
            'password' => Hash::make($request->validated('password')),
            'role' => $request->validated('role', 'customer'),
        ]);

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
