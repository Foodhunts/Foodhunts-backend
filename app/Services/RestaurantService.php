<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class RestaurantService
{
    public function publicListings(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Restaurant::query()
            ->where('is_active', true)
            ->where('kyc_status', 'approved')
            ->orderBy('name')
            ->orderBy('id');

        if ($search !== null && $search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%")
                    ->orWhere('city', 'ilike', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function publicDetails(Restaurant $restaurant): Restaurant
    {
        abort_unless($restaurant->is_active && $restaurant->kyc_status === 'approved', 404);

        return $restaurant;
    }

    public function publicMenuItems(Restaurant $restaurant, int $perPage = 100): LengthAwarePaginator
    {
        abort_unless($restaurant->is_active && $restaurant->kyc_status === 'approved', 404);

        return MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true)
            ->with('menu')
            ->orderBy('menu_id')
            ->orderBy('name')
            ->paginate($perPage);
    }
}
