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

    /**
     * A user may manage a restaurant's media when they own it.
     *
     * Ownership mirrors the store app's own rule (services/menus.ts): the
     * authenticated user is the owner when their id matches owner_id, when a
     * legacy owner-less restaurant row's id is the user id, or when the
     * restaurant's owner_email matches the user's email (email-linked stores).
     *
     * NOTE: the shared production users table has no `role` column, so a role
     * check reads null and would deny everyone. Role is therefore treated as an
     * optional *widening* signal only: if a role column is added later and marks
     * the user a restaurant owner, that also grants access, but its absence
     * never blocks a genuine owner.
     */
    private function isRestaurantOwner(User $user, Restaurant $restaurant): bool
    {
        $userId = (string) $user->getKey();
        $userEmail = strtolower(trim((string) ($user->email ?? '')));
        $ownerId = $restaurant->owner_id === null ? null : (string) $restaurant->owner_id;
        $ownerEmail = strtolower(trim((string) ($restaurant->owner_email ?? '')));

        $ownsById = $ownerId !== null && $ownerId === $userId;
        $ownsByLegacyId = $ownerId === null && (string) $restaurant->getKey() === $userId;
        $ownsByEmail = $userEmail !== '' && $ownerEmail !== '' && $ownerEmail === $userEmail;

        if ($ownsById || $ownsByLegacyId || $ownsByEmail) {
            return true;
        }

        // Optional widening: honour an explicit restaurant-owner role paired with
        // an id match, for any future deployment that populates users.role.
        $role = $user->role instanceof \BackedEnum ? $user->role->value : $user->role;

        return $role === Role::RestaurantOwner->value && $ownsById;
    }
}
