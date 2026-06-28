<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateBuyForMeRequest;
use App\Models\BuyForMeRequest;
use App\Services\BuyForMeService;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuyForMeController extends Controller
{
    public function __construct(
        private readonly BuyForMeService $buyForMeService,
        private readonly FeatureFlagService $featureFlagService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);

        return response()->json(
            $request->user()->buyForMeRequests()->with(['restaurant', 'order'])->latest()->paginate(20)
        );
    }

    public function store(CreateBuyForMeRequest $request): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);

        $buyForMeRequest = $this->buyForMeService->createRequest($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'token' => $buyForMeRequest->token,
            'share_url' => rtrim(config('foodhunts.frontend_url'), '/').'/buy-for-me/'.$buyForMeRequest->token,
            'expires_at' => $buyForMeRequest->expires_at,
            'total_amount' => $buyForMeRequest->total_amount,
        ], 201);
    }

    public function show(Request $request, BuyForMeRequest $buyForMeRequest): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);
        abort_unless($buyForMeRequest->requester_user_id === $request->user()->id, 403);

        return response()->json($buyForMeRequest->load(['restaurant', 'order']));
    }

    public function cancel(Request $request, BuyForMeRequest $buyForMeRequest): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);
        abort_unless($buyForMeRequest->requester_user_id === $request->user()->id, 403);

        return response()->json([
            'success' => true,
            'request' => $this->buyForMeService->cancel($buyForMeRequest),
        ]);
    }
}
