<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentAttempt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'order_id',
        'provider',
        'reference',
        'amount',
        'currency',
        'status',
        'gateway_response',
        'raw_payload',
        'verified_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'raw_payload' => 'array',
        'verified_at' => 'datetime',
    ];
}
