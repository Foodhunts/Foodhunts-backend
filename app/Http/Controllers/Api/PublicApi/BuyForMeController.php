<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicApi\PayBuyForMeRequest;
use App\Services\BuyForMeService;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;

class BuyForMeController extends Controller
{
    public function __construct(
        private readonly BuyForMeService $buyForMeService,
        private readonly FeatureFlagService $featureFlagService,
    )
    {
    }

    public function show(string $token): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);

        $request = $this->buyForMeService->findByToken($token);

        return response()->json([
            'success' => true,
            'data' => $this->buyForMeService->publicSummary($request->load(['restaurant'])),
        ]);
    }

    public function pay(string $token, PayBuyForMeRequest $request): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('BUY_FOR_ME'), 404);

        $buyForMeRequest = $this->buyForMeService->findByToken($token);
        $result = $this->buyForMeService->initiatePublicPayment($buyForMeRequest, $request->validated());

        return response()->json([
            'success' => true,
            'authorization_url' => $result['authorization_url'],
            'reference' => $result['reference'],
            'status' => $result['status'],
        ]);
    }
}
