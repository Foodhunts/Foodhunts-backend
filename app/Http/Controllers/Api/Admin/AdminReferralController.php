<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\ReferralReward;

class AdminReferralController extends Controller
{
    public function index()
    {
        return response()->json(
            Referral::query()->with(['referrer:id,first_name,last_name,email,phone,referral_code', 'referred:id,first_name,last_name,email,phone,referral_code'])->latest()->paginate(20)
        );
    }

    public function rewards()
    {
        return response()->json(
            ReferralReward::query()->with(['referrer:id,first_name,last_name,email,phone,referral_code', 'referred:id,first_name,last_name,email,phone,referral_code', 'order:id,total_amount,payment_status'])->latest()->paginate(20)
        );
    }
}
