<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\InitializePaymentRequest;
use App\Http\Requests\Payments\VerifyPaymentRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService)
    {
    }

    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        return response()->json($this->paymentService->initialize($request->user(), $request->validated()));
    }

    public function verify(VerifyPaymentRequest $request): JsonResponse
    {
        return response()->json($this->paymentService->verify($request->validated()));
    }

    public function webhook(Request $request): JsonResponse
    {
        return response()->json($this->paymentService->handleWebhook($request));
    }
}
