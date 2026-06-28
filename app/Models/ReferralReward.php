<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralReward extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'order_id',
        'reward_percentage',
        'order_amount',
        'reward_amount',
        'wallet_transaction_id',
        'status',
        'failure_reason',
    ];

    protected $casts = [
        'reward_percentage' => 'decimal:2',
        'order_amount' => 'decimal:2',
        'reward_amount' => 'decimal:2',
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
