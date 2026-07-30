<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use Illuminate\Http\JsonResponse;

class AdminPaymentAttemptController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Payment attempts fetched.',
            'data' => PaymentAttempt::query()
                ->latest()
                ->paginate(20),
        ]);
    }
}
