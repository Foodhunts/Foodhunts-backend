<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'submitted_fee' => 'decimal:2',
        'applied_fee' => 'decimal:2',
        'rider_lat' => 'decimal:7',
        'rider_lng' => 'decimal:7',
        'last_event_sequence' => 'integer',
        'dispatch_attempts' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'metadata' => 'array',
        'rider_location_updated_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'accepted_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeliveryEvent::class);
    }

    public function isTerminal(): bool
    {
        return DeliveryStatus::isTerminal($this->status);
    }

    /**
     * Whether a rider is currently carrying this delivery.
     *
     * Used to decide whether cancelling is still free of consequence, so it
     * deliberately errs towards true: an unrecognised status counts as assigned
     * rather than not, because treating an unknown state as "no rider" would
     * silently cancel a delivery someone is already riding.
     */
    public function hasRiderAssigned(): bool
    {
        return $this->rider_name !== null
            || ! in_array($this->status, [
                DeliveryStatus::Pending->value,
                DeliveryStatus::DispatchFailed->value,
                DeliveryStatus::Accepted->value,
            ], true);
    }
}
