<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'rider_id' => $this->rider_id,
            'status' => $this->status?->value,
            'dispatch_mode' => $this->dispatch_mode,
            'delivery_code' => $this->when(
                $this->rider?->user_id === $request->user()?->id,
                $this->delivery_code
            ),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'order' => new OrderResource($this->whenLoaded('order')),
            'rider' => new RiderResource($this->whenLoaded('rider')),
        ];
    }
}
