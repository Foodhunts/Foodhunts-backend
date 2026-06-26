<?php

namespace App\Services;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function credit(User $user, array $data): array
    {
        return $this->applyChange($user, WalletTransactionType::Credit, $data);
    }

    public function debit(User $user, array $data): array
    {
        return $this->applyChange($user, WalletTransactionType::Debit, $data);
    }

    private function applyChange(User $user, WalletTransactionType $type, array $data): array
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'currency' => 'NGN']
        );

        return DB::transaction(function () use ($wallet, $user, $type, $data): array {
            $balanceBefore = (float) $wallet->balance;
            $amount = (float) $data['amount'];
            $balanceAfter = $type === WalletTransactionType::Debit
                ? $balanceBefore - $amount
                : $balanceBefore + $amount;

            $wallet->update(['balance' => $balanceAfter]);

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => $type->value,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $data['reference'] ?? null,
                'metadata' => ['note' => $data['note'] ?? null],
            ]);

            return [
                'wallet' => $wallet->refresh(),
                'transaction' => $transaction,
            ];
        });
    }
}
