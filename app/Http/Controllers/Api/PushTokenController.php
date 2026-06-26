<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Push\StorePushTokenRequest;
use App\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;

class PushTokenController extends Controller
{
    public function __construct(private readonly PushNotificationService $pushNotificationService)
    {
    }

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        return response()->json($this->pushNotificationService->registerToken($request->user(), $request->validated()), 201);
    }
}
