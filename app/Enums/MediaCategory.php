<?php

declare(strict_types=1);

namespace App\Enums;

enum MediaCategory: string
{
    case RestaurantLogo = 'restaurant_logo';
    case RestaurantHeader = 'restaurant_header';
    case RestaurantCover = 'restaurant_cover';
    case MenuImage = 'menu_image';
    case MenuItemImage = 'menu_item_image';
    case PromotionImage = 'promotion_image';
    case RestaurantKyc = 'restaurant_kyc';
    case OwnerNationalId = 'owner_national_id';

    public function isPublic(): bool
    {
        return match ($this) {
            self::RestaurantKyc, self::OwnerNationalId => false,
            default => true,
        };
    }

    public function allowsPermanentUrl(): bool
    {
        return $this->isPublic();
    }

    /** @return list<string> */
    public function allowedContentTypes(): array
    {
        return match ($this) {
            self::RestaurantKyc, self::OwnerNationalId => [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            default => ['image/jpeg', 'image/png', 'image/webp'],
        };
    }

    public function maxBytes(): int
    {
        $configKey = $this->isPublic()
            ? 'media.limits.public_image_kb'
            : 'media.limits.private_document_kb';

        return (int) config($configKey) * 1024;
    }
}
