<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BuyForMeRequest;

class AdminBuyForMeController extends Controller
{
    public function index()
    {
        return response()->json(
            BuyForMeRequest::query()->with(['requester:id,first_name,last_name,email,phone,referral_code', 'restaurant', 'order'])->latest()->paginate(20)
        );
    }

    public function show(BuyForMeRequest $buyForMeRequest)
    {
        return response()->json($buyForMeRequest->load(['requester', 'restaurant', 'order']));
    }
}
