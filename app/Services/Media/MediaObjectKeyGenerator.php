<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaCategory;
use App\Services\Media\Data\MediaObjectContext;
use App\Services\Media\Exceptions\InvalidMediaObjectKeyException;
use Illuminate\Support\Str;

final class MediaObjectKeyGenerator
{
    public function generate(
        MediaCategory $category,
        MediaObjectContext $context,
        string $contentType,
    ): string {
        if (! in_array($contentType, $category->allowedContentTypes(), true)) {
            throw new InvalidMediaObjectKeyException('Unsupported media content type.');
        }

        $extension = match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => throw new InvalidMediaObjectKeyException('Unsupported media content type.'),
        };

        $filename = Str::uuid()->toString().'.'.$extension;

        return match ($category) {
            MediaCategory::RestaurantLogo => $this->restaurantPath($context, 'logo', $filename),
            MediaCategory::RestaurantHeader => $this->restaurantPath($context, 'headers', $filename),
            MediaCategory::RestaurantCover => $this->restaurantPath($context, 'covers', $filename),
            MediaCategory::MenuImage => $this->relatedRestaurantPath($context, 'menus', $filename),
            MediaCategory::MenuItemImage => $this->relatedRestaurantPath($context, 'menu-items', $filename),
            MediaCategory::PromotionImage => $this->promotionPath($context, $filename),
            MediaCategory::RestaurantKyc => $this->privateRestaurantPath($context, 'kyc', $filename),
            MediaCategory::OwnerNationalId => $this->privateRestaurantPath($context, 'owner-id', $filename),
        };
    }

    public function assertAllowed(string $objectKey): void
    {
        if (! self::isAllowed($objectKey)) {
            throw new InvalidMediaObjectKeyException('The media object key is not application-generated.');
        }
    }

    public static function isAllowed(string $objectKey): bool
    {
        if (
            trim($objectKey) !== $objectKey
            || $objectKey === ''
            || str_contains($objectKey, '\\')
            || str_contains($objectKey, '..')
            || str_contains($objectKey, '//')
            || str_starts_with($objectKey, '/')
        ) {
            return false;
        }

        $segments = explode('/', $objectKey);
        $private = $segments[0] ?? null;
        $offset = $private === 'private' ? 1 : 0;

        if (($segments[$offset] ?? null) === 'restaurants') {
            $restaurantId = $segments[$offset + 1] ?? '';
            if (! self::isUuid($restaurantId)) {
                return false;
            }

            $kind = $segments[$offset + 2] ?? '';
            if (in_array($kind, ['logo', 'headers', 'covers', 'kyc', 'owner-id'], true)) {
                return count($segments) === $offset + 4
                    && self::isUuidFilename($segments[$offset + 3]);
            }

            if (in_array($kind, ['menus', 'menu-items'], true)) {
                return count($segments) === $offset + 5
                    && self::isUuid($segments[$offset + 3])
                    && self::isUuidFilename($segments[$offset + 4]);
            }

            return false;
        }

        if (($segments[$offset] ?? null) === 'promotions') {
            return count($segments) === $offset + 3
                && self::isUuid($segments[$offset + 1] ?? '')
                && self::isUuidFilename($segments[$offset + 2] ?? '');
        }

        return false;
    }

    public static function isPrivate(string $objectKey): bool
    {
        return str_starts_with($objectKey, 'private/') && self::isAllowed($objectKey);
    }

    private function restaurantPath(
        MediaObjectContext $context,
        string $namespace,
        string $filename,
    ): string {
        $restaurantId = $this->requiredId($context->restaurantId, 'restaurant');

        return "restaurants/{$restaurantId}/{$namespace}/{$filename}";
    }

    private function relatedRestaurantPath(
        MediaObjectContext $context,
        string $namespace,
        string $filename,
    ): string {
        $restaurantId = $this->requiredId($context->restaurantId, 'restaurant');
        $relatedId = $this->requiredId($context->relatedId, 'related entity');

        return "restaurants/{$restaurantId}/{$namespace}/{$relatedId}/{$filename}";
    }

    private function promotionPath(MediaObjectContext $context, string $filename): string
    {
        $promotionId = $this->requiredId($context->relatedId, 'promotion');

        return "promotions/{$promotionId}/{$filename}";
    }

    private function privateRestaurantPath(
        MediaObjectContext $context,
        string $namespace,
        string $filename,
    ): string {
        $restaurantId = $this->requiredId($context->restaurantId, 'restaurant');

        return "private/restaurants/{$restaurantId}/{$namespace}/{$filename}";
    }

    private function requiredId(?string $value, string $label): string
    {
        if (! is_string($value) || ! self::isUuid($value)) {
            throw new InvalidMediaObjectKeyException("Invalid {$label} identifier.");
        }

        return strtolower($value);
    }

    private static function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    private static function isUuidFilename(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|png|webp|pdf)$/i',
            $value,
        );
    }
}
