<?php

namespace Tests\Unit;

use App\Exceptions\InvalidSupabaseTokenException;
use App\Exceptions\SupabaseAuthUnavailableException;
use App\Services\SupabaseAuthService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupabaseAuthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('supabase.url', 'https://example.supabase.co');
        Config::set('supabase.publishable_key', 'test-publishable-key');
        Config::set('supabase.auth_timeout', 5);
        Config::set('supabase.auth_connect_timeout', 3);
        Http::preventStrayRequests();
    }

    public function test_valid_user_response_sends_authentication_headers_and_returns_verified_uuid(): void
    {
        Http::fake([
            'https://example.supabase.co/auth/v1/user' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111',
                'user_metadata' => ['role' => 'admin'],
            ], 200),
        ]);

        $result = app(SupabaseAuthService::class)->user('test-access-token');

        $this->assertSame(['id' => '11111111-1111-4111-8111-111111111111'], $result);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://example.supabase.co/auth/v1/user'
                && $request->hasHeader('apikey', 'test-publishable-key')
                && $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    public function test_401_token_response_is_invalid(): void
    {
        Http::fake([
            '*' => Http::response([], 401),
        ]);

        $this->expectException(InvalidSupabaseTokenException::class);

        app(SupabaseAuthService::class)->user('rejected-token');
    }

    public function test_403_token_response_is_invalid(): void
    {
        Http::fake([
            '*' => Http::response([], 403),
        ]);

        $this->expectException(InvalidSupabaseTokenException::class);

        app(SupabaseAuthService::class)->user('rejected-token');
    }

    public function test_429_response_is_reported_as_temporary(): void
    {
        Http::fake([
            '*' => Http::response([], 429),
        ]);

        $this->expectException(SupabaseAuthUnavailableException::class);

        app(SupabaseAuthService::class)->user('access-token');
    }

    public function test_500_response_is_reported_as_temporary(): void
    {
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $this->expectException(SupabaseAuthUnavailableException::class);

        app(SupabaseAuthService::class)->user('access-token');
    }

    public function test_invalid_user_response_shape_is_rejected(): void
    {
        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $this->expectException(InvalidSupabaseTokenException::class);

        app(SupabaseAuthService::class)->user('access-token');
    }
}
