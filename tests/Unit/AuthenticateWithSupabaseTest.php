<?php

namespace Tests\Unit;

use App\Http\Middleware\AuthenticateWithSupabase;
use App\Services\SupabaseAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticateWithSupabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
        ]);
    }

    public function test_missing_bearer_token_returns_json_401(): void
    {
        $response = app(AuthenticateWithSupabase::class)->handle(
            Request::create('/api/auth/me', 'GET'),
            fn ($request) => response()->json(['unexpected' => true]),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthenticated.', $response->getData(true)['message']);
    }

    public function test_auth_service_outage_returns_json_503(): void
    {
        Http::fake([
            '*' => Http::response([], 503),
        ]);

        $request = Request::create('/api/auth/me', 'GET');
        $request->headers->set('Authorization', 'Bearer test-token');

        $response = app(AuthenticateWithSupabase::class)->handle(
            $request,
            fn ($request) => response()->json(['unexpected' => true]),
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('AUTHENTICATION_UPSTREAM_UNAVAILABLE', $response->getData(true)['code']);
    }
}
