<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FeatureFlagService;
use Illuminate\Http\JsonResponse;

class FeatureFlagController extends Controller
{
    public function __construct(private readonly FeatureFlagService $featureFlagService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->featureFlagService->all());
    }
}
