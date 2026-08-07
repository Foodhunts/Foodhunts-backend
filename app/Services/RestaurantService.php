<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

class RestaurantService
{
    public function publicListings(): Collection
    {
        return Restaurant::query()
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->latest()
            ->get();
    }

    public function publicDetails(Restaurant $restaurant): Restaurant
    {
        if (! $restaurant->is_active || $restaurant->kyc_status !== 'approved') {
            abort(404);
        }

        return $restaurant;
    }

    public function publicMenuItems(Restaurant $restaurant): Collection
    {
        $restaurant = $this->publicDetails($restaurant);

        return MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true)
            ->latest()
            ->get();
    }
}
