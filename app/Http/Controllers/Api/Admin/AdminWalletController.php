<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreditWalletRequest;
use App\Http\Requests\Admin\DebitWalletRequest;
use App\Services\WalletService;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminWalletController extends Controller
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    public function credit(CreditWalletRequest $request, User $user): JsonResponse
    {
        return response()->json($this->walletService->credit($user, $request->validated()));
    }

    public function debit(DebitWalletRequest $request, User $user): JsonResponse
    {
        return response()->json($this->walletService->debit($user, $request->validated()));
    }
}
