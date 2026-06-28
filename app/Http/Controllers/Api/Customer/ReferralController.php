<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ApplyReferralCodeRequest;
use App\Services\FeatureFlagService;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referralService,
        private readonly FeatureFlagService $featureFlagService,
    )
    {
    }

    public function me(Request $request): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('REFERRAL_CODE'), 404);

        return response()->json($this->referralService->buildReferralSummary($request->user()));
    }

    public function apply(ApplyReferralCodeRequest $request): JsonResponse
    {
        abort_unless($this->featureFlagService->enabled('REFERRAL_CODE'), 404);

        $referral = $this->referralService->applyReferralCode($request->user(), $request->validated('referral_code'));

        return response()->json([
            'success' => true,
            'referral' => $referral,
        ]);
    }
}
