<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SupabaseIdentity;
use App\Exceptions\InvalidSupabaseTokenException;
use App\Exceptions\SupabaseAuthUnavailableException;
use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies a Supabase access token without requiring a duplicate public.users
 * profile. Media ownership is stored against Supabase user IDs on restaurants.
 */
final class AuthenticateSupabaseIdentity
{
    public function __construct(private readonly SupabaseAuthService $supabaseAuth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->bearerToken();

        if (! is_string($accessToken) || $accessToken === '') {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        try {
            $verifiedUser = $this->supabaseAuth->user($accessToken);
        } catch (InvalidSupabaseTokenException) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        } catch (SupabaseAuthUnavailableException) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication service temporarily unavailable.',
                'code' => 'AUTHENTICATION_UPSTREAM_UNAVAILABLE',
            ], 503);
        }

        $request->attributes->set(
            SupabaseIdentity::REQUEST_ATTRIBUTE,
            new SupabaseIdentity($verifiedUser['id'], $verifiedUser['email']),
        );

        return $next($request);
    }
}
