<?php

namespace App\Services;

use App\Exceptions\InvalidSupabaseTokenException;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(private readonly SupabaseAuthService $supabaseAuth)
    {
    }

    public function register(RegisterRequest $request): array
    {
        try {
            $session = $this->supabaseAuth->signUp($request->validated());
        } catch (InvalidSupabaseTokenException) {
            throw ValidationException::withMessages([
                'email' => ['Unable to register with the supplied credentials.'],
            ]);
        }

        $user = User::query()->find($session['user_id']);

        return [
            'user' => $user ? new UserResource($user) : null,
            'token' => $session['access_token'] ?: null,
            'requires_confirmation' => $session['access_token'] === null,
        ];
    }

    public function login(LoginRequest $request): array
    {
        try {
            $session = $this->supabaseAuth->signIn(
                $request->validated('login'),
                $request->validated('password'),
            );
        } catch (InvalidSupabaseTokenException) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user = User::query()->find($session['user_id']);

        return [
            'user' => $user ? new UserResource($user) : null,
            'token' => $session['access_token'],
        ];
    }

    public function logout(string $accessToken): void
    {
        $this->supabaseAuth->logout($accessToken);
    }

    public function recoverPassword(string $email): void
    {
        $this->supabaseAuth->recover($email);
    }
}
