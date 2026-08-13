<?php

namespace App\Models;

use App\Enums\RiderKycStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rider extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'vehicle_type',
        'plate_number',
        'kyc_status',
        'kyc_reviewer_note',
        'is_online',
        'latitude',
        'longitude',
        'location_updated_at',
        'rating',
    ];

    protected $casts = [
        'kyc_status' => RiderKycStatus::class,
        'is_online' => 'bool',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'location_updated_at' => 'datetime',
        'rating' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(RiderDocument::class);
    }

    public function deliveries()
    {
        return $this->hasMany(OrderDelivery::class);
    }
}
