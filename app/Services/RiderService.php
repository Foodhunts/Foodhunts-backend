<?php

namespace App\Services;

use App\Enums\RiderKycStatus;
use App\Models\Rider;
use App\Models\RiderDocument;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RiderService
{
    public function apply(User $user, array $data): Rider
    {
        $rider = Rider::updateOrCreate(
            ['user_id' => $user->id],
            [
                'vehicle_type' => $data['vehicle_type'] ?? 'motorcycle',
                'plate_number' => $data['plate_number'] ?? null,
                'kyc_status' => RiderKycStatus::Submitted->value,
            ]
        );

        return $rider;
    }

    public function updateProfile(Rider $rider, array $data): Rider
    {
        $rider->update([
            'vehicle_type' => $data['vehicle_type'] ?? $rider->vehicle_type,
            'plate_number' => $data['plate_number'] ?? $rider->plate_number,
        ]);

        return $rider;
    }

    public function addDocument(Rider $rider, array $data): RiderDocument
    {
        return RiderDocument::create([
            'rider_id' => $rider->id,
            'document_type' => $data['document_type'],
            'storage_key' => $data['storage_key'],
            'mime' => $data['mime'] ?? null,
            'size_bytes' => $data['size_bytes'] ?? null,
        ]);
    }

    public function setOnline(Rider $rider, bool $online): Rider
    {
        if ($online && $rider->kyc_status !== RiderKycStatus::Approved) {
            throw ValidationException::withMessages([
                'is_online' => ['Rider must be KYC approved before going online.'],
            ]);
        }

        $rider->update(['is_online' => $online]);

        return $rider;
    }

    public function updateLocation(Rider $rider, float $latitude, float $longitude): Rider
    {
        $rider->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_updated_at' => now(),
        ]);

        return $rider;
    }

    public function review(Rider $rider, bool $approved, ?string $note = null): Rider
    {
        $rider->update([
            'kyc_status' => $approved ? RiderKycStatus::Approved->value : RiderKycStatus::Rejected->value,
            'kyc_reviewer_note' => $note,
            'is_online' => $approved ? (bool) ($rider->is_online ?? false) : false,
        ]);

        return $rider;
    }
}
