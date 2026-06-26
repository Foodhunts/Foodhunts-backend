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
            ->latest()
            ->get();
    }

    public function publicDetails(Restaurant $restaurant): Restaurant
    {
        return $restaurant;
    }

    public function publicMenuItems(Restaurant $restaurant): Collection
    {
        return MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true)
            ->latest()
            ->get();
    }
}
