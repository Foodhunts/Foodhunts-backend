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
        return $this->applyChange($user, WalletTransactionType::Credit, $data, 'credit');
    }

    public function debit(User $user, array $data): array
    {
        return $this->applyChange($user, WalletTransactionType::Debit, $data, 'debit');
    }

    public function record(User $user, WalletTransactionType $type, array $data): array
    {
        $direction = $data['direction'] ?? 'credit';

        return $this->applyChange($user, $type, $data, $direction);
    }

    private function applyChange(User $user, WalletTransactionType $type, array $data, string $direction): array
    {
        return DB::transaction(function () use ($user, $type, $data, $direction): array {
            $wallet = Wallet::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['balance' => 0, 'currency' => 'NGN']
            );
            $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $balanceBefore = (float) $wallet->balance;
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Wallet amount must be greater than zero.');
            }
            if ($direction === 'debit' && $balanceBefore < $amount) {
                throw new \RuntimeException('Insufficient wallet balance.');
            }
            $reference = $data['reference'] ?? null;
            if ($reference && WalletTransaction::query()->where('reference', $reference)->exists()) {
                return ['wallet' => $wallet, 'transaction' => WalletTransaction::query()->where('reference', $reference)->first(), 'status' => 'already_processed'];
            }
            $balanceAfter = $direction === 'debit'
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
                'order_id' => $data['order_id'] ?? null,
                'category' => $data['category'] ?? null,
                'direction' => null,
                'status' => $data['status'] ?? 'completed',
                'metadata' => $data['metadata'] ?? ['note' => $data['note'] ?? null],
            ]);

            return [
                'wallet' => $wallet->refresh(),
                'transaction' => $transaction,
            ];
        });
    }
}
