<?php

declare(strict_types=1);

namespace App\Services\Media\Data;

final readonly class MediaObjectContext
{
    private function __construct(
        public ?string $restaurantId = null,
        public ?string $relatedId = null,
    ) {}

    public static function restaurant(string $restaurantId): self
    {
        return new self($restaurantId);
    }

    public static function menu(string $restaurantId, string $menuId): self
    {
        return new self($restaurantId, $menuId);
    }

    public static function menuItem(string $restaurantId, string $menuItemId): self
    {
        return new self($restaurantId, $menuItemId);
    }

    public static function promotion(string $promotionId): self
    {
        return new self(null, $promotionId);
    }
}
