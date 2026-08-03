<?php

namespace App\Services;

use App\Exceptions\InvalidSupabaseTokenException;
use App\Exceptions\SupabaseAuthUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SupabaseAuthService
{
    public function user(string $accessToken): array
    {
        $response = $this->send(
            'get',
            '/auth/v1/user',
            [],
            $accessToken
        );

        return $this->normalizeUser($response->json());
    }

    public function signIn(string $identifier, string $password): array
    {
        $field = str_contains($identifier, '@') ? 'email' : 'phone';

        $response = $this->send('post', '/auth/v1/token?grant_type=password', [
            $field => $identifier,
            'password' => $password,
        ]);

        if ($response->status() === 400 || $response->status() === 401 || $response->status() === 403) {
            throw new InvalidSupabaseTokenException();
        }

        return $this->normalizeSession($response->json());
    }

    public function signUp(array $attributes): array
    {
        $credentials = [
            'password' => $attributes['password'],
            'data' => array_filter([
                'name' => $attributes['name'] ?? null,
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'phone' => $attributes['phone'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];

        if (! empty($attributes['email'])) {
            $credentials['email'] = $attributes['email'];
        } else {
            $credentials['phone'] = $attributes['phone'];
        }

        $response = $this->send('post', '/auth/v1/signup', $credentials);

        if ($response->status() === 400 || $response->status() === 401 || $response->status() === 403) {
            throw new InvalidSupabaseTokenException();
        }

        $payload = $response->json();
        $user = $this->normalizeUser($payload['user'] ?? $payload);

        return [
            'user_id' => $user['id'],
            'access_token' => $payload['access_token'] ?? null,
        ];
    }

    public function logout(string $accessToken): void
    {
        $response = $this->send('post', '/auth/v1/logout', [], $accessToken);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new InvalidSupabaseTokenException();
        }
    }

    public function recover(string $email): void
    {
        $response = $this->send('post', '/auth/v1/recover', ['email' => $email]);

        if ($response->status() === 400 || $response->status() === 401 || $response->status() === 403) {
            throw new InvalidSupabaseTokenException();
        }
    }

    private function send(string $method, string $path, array $payload = [], ?string $accessToken = null): Response
    {
        $url = (string) config('supabase.url');
        $publishableKey = (string) config('supabase.publishable_key');

        if ($url === '' || $publishableKey === '') {
            throw new SupabaseAuthUnavailableException();
        }

        $headers = [
            'apikey' => $publishableKey,
            'Accept' => 'application/json',
        ];

        if ($accessToken !== null) {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        try {
            $request = Http::withHeaders($headers)
                ->connectTimeout((int) config('supabase.auth_connect_timeout', 3))
                ->timeout((int) config('supabase.auth_timeout', 5));

            $response = $method === 'get'
                ? $request->get($url.$path)
                : $request->post($url.$path, $payload);
        } catch (ConnectionException) {
            throw new SupabaseAuthUnavailableException();
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new SupabaseAuthUnavailableException();
        }

        if (! $response->successful()) {
            throw new InvalidSupabaseTokenException();
        }

        return $response;
    }

    private function normalizeSession(array $payload): array
    {
        $user = $this->normalizeUser($payload['user'] ?? []);

        return [
            'user_id' => $user['id'],
            'access_token' => (string) ($payload['access_token'] ?? ''),
        ];
    }

    private function normalizeUser(mixed $payload): array
    {
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;

        if (! is_string($id) || ! preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id
        )) {
            throw new InvalidSupabaseTokenException();
        }

        return ['id' => $id];
    }
}
