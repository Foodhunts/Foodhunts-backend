<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'referral_code' => $this->referral_code,
            'referred_by_user_id' => $this->referred_by_user_id,
            'referred_at' => $this->referred_at,
            'created_at' => $this->created_at,
        ];
    }
}
