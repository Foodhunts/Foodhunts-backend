<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidSupabaseTokenException;
use App\Exceptions\SupabaseAuthUnavailableException;
use App\Models\User;
use App\Services\SupabaseAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithSupabase
{
    public function __construct(private readonly SupabaseAuthService $supabaseAuth)
    {
    }

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

        $user = User::query()->find($verifiedUser['id']);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User profile not found.',
                'code' => 'PROFILE_NOT_FOUND',
            ], 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(static fn (): User => $user);

        return $next($request);
    }
}
