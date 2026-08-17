<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StorePlatformReviewRequest;
use App\Http\Resources\PlatformReviewResource;
use App\Models\PlatformReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $review = $request->user()->platformReview;

        return response()->json([
            'success' => true,
            'data' => $review ? new PlatformReviewResource($review) : null,
        ]);
    }

    public function store(StorePlatformReviewRequest $request): JsonResponse
    {
        $review = PlatformReview::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Thanks for sharing your feedback!',
            'data' => new PlatformReviewResource($review),
        ], 201);
    }

    public function summary(): JsonResponse
    {
        $summary = PlatformReview::query()
            ->selectRaw('COALESCE(AVG(rating), 0) AS average_rating, COUNT(*) AS total_reviews')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'average_rating' => round((float) $summary->average_rating, 1),
                'total_reviews' => (int) $summary->total_reviews,
            ],
        ]);
    }
}
