<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

final class MediaPolicy
{
    public function updateRestaurantMedia(User $user, Restaurant $restaurant): bool
    {
        return $this->isRestaurantOwner($user, $restaurant);
    }

    public function updateMenuItemMedia(User $user, Restaurant $restaurant, MenuItem $menuItem): bool
    {
        return $this->isRestaurantOwner($user, $restaurant)
            && (string) $menuItem->restaurant_id === (string) $restaurant->getKey();
    }

    private function isRestaurantOwner(User $user, Restaurant $restaurant): bool
    {
        $role = $user->role instanceof \BackedEnum ? $user->role->value : $user->role;

        return $role === Role::RestaurantOwner->value
            && (string) $restaurant->owner_id === (string) $user->getKey();
    }
}
