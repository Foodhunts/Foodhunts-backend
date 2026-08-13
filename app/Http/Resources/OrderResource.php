<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'restaurant_id' => $this->restaurant_id,
            'delivery_address_id' => $this->delivery_address_id,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => (float) $this->subtotal,
            'delivery_fee' => $this->delivery_fee === null ? null : (float) $this->delivery_fee,
            'service_charge' => $this->service_charge === null ? null : (float) $this->service_charge,
            'tax_amount' => $this->tax_amount === null ? null : (float) $this->tax_amount,
            'discount_amount' => $this->discount_amount === null ? null : (float) $this->discount_amount,
            'total_amount' => (float) $this->total_amount,
            'currency' => $this->currency,
            'payment_reference' => $this->payment_reference,
            'payment_provider' => $this->payment_provider,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'restaurant' => $this->relationLoaded('restaurant') ? new RestaurantResource($this->restaurant) : null,
            'user' => $this->relationLoaded('user') ? new UserResource($this->user) : null,
            'address' => $this->relationLoaded('address') ? new AddressResource($this->address) : null,
            'created_at' => $this->created_at,
        ];
    }
}
