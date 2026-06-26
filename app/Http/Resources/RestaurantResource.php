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
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'phone_number' => $this->phone_number,
            'logo_url' => $this->logo_url,
            'cover_image_url' => $this->cover_image_url,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
