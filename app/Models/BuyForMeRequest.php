<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BuyForMeRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'token',
        'requester_user_id',
        'restaurant_id',
        'cart_snapshot',
        'delivery_address_snapshot',
        'delivery_address_id',
        'subtotal',
        'delivery_fee',
        'service_fee',
        'discount_amount',
        'total_amount',
        'currency',
        'message',
        'payer_name',
        'payer_email',
        'payer_phone',
        'payment_reference',
        'payment_provider',
        'status',
        'order_id',
        'expires_at',
        'paid_at',
        'failure_reason',
    ];

    protected $casts = [
        'cart_snapshot' => 'array',
        'delivery_address_snapshot' => 'array',
        'subtotal' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
