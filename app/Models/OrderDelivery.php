<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderDelivery extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'order_id',
        'rider_id',
        'status',
        'dispatch_mode',
        'delivery_code',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'location_snapshot',
        'metadata',
    ];

    protected $casts = [
        'status' => DeliveryStatus::class,
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'location_snapshot' => 'array',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}
