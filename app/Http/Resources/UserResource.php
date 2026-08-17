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
            'phone' => $this->phone_number,
            'phone_number' => $this->phone_number,
            'role' => $this->role,
            'is_admin' => $this->role?->value === 'admin',
            'onboarding_complete' => (bool) ($this->onboarding_complete ?? false),
            'referral_code' => $this->referral_code,
            'referred_by_user_id' => $this->referred_by_user_id,
            'referred_at' => $this->referred_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
