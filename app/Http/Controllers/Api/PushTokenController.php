<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\StorePushTokenRequest;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\PushToken;

class PushTokenController extends Controller
{
    public function __construct(private readonly PushNotificationService $pushNotificationService)
    {
    }

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        return response()->json($this->pushNotificationService->registerToken($request->user(), $request->validated()), 201);
    }

    public function destroy(Request $request, PushToken $pushToken): JsonResponse
    {
        abort_unless($pushToken->user_id === $request->user()->id, 403);
        $pushToken->delete();
        return response()->json(['success' => true, 'message' => 'Push token removed successfully', 'data' => []]);
    }
}
