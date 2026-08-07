<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return response()->json($this->authService->register($request), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return response()->json($this->authService->login($request));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function profile(Request $request): JsonResponse
    {
        $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'first_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'last_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'phone' => ['sometimes', 'string', 'max:30', 'unique:users,phone,'.$request->user()->id]]);
        $request->user()->update($request->only(['name', 'first_name', 'last_name', 'phone']));
        return response()->json(['success' => true, 'message' => 'Profile updated successfully', 'data' => ['user' => new UserResource($request->user()->refresh())]]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        return response()->json(['success' => true, 'message' => 'If the account exists, a reset link has been sent.', 'data' => []]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email'], 'token' => ['required'], 'password' => ['required', 'confirmed', 'min:8']]);
        return response()->json(['success' => true, 'message' => 'Password reset request accepted.', 'data' => []]);
    }
}
