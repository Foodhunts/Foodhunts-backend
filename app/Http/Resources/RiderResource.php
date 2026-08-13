<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RiderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'phone' => $this->user?->phone,
            'email' => $this->user?->email,
            'vehicle_type' => $this->vehicle_type,
            'plate_number' => $this->plate_number,
            'kyc_status' => $this->kyc_status?->value,
            'kyc_reviewer_note' => $this->kyc_reviewer_note,
            'is_online' => $this->is_online,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'location_updated_at' => $this->location_updated_at?->toIso8601String(),
            'rating' => $this->rating,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
