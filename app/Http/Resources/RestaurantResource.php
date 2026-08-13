<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone_number' => $this->phone_number,
            'logo_url' => $this->logo_url,
            'cover_image_url' => $this->header_image_url,
            'header_image_url' => $this->header_image_url,
            'street' => $this->street,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'type' => $this->type,
            'business_hours' => $this->businessHoursObject(),
            'is_active' => $this->is_active,
            'kyc_status' => $this->kyc_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** business_hours is stored as jsonb; decode so the app receives an object. */
    private function businessHoursObject(): ?object
    {
        $value = $this->business_hours;
        if (is_string($value)) {
            $decoded = json_decode($value);
            return is_object($decoded) || is_array($decoded) ? $decoded : null;
        }

        return $value;
    }
}
