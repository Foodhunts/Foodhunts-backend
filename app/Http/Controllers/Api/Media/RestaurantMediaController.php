<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Media;

use App\Auth\SupabaseIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UploadMenuItemImageRequest;
use App\Http\Requests\Media\UploadRestaurantCoverRequest;
use App\Http\Requests\Media\UploadRestaurantLogoRequest;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Policies\MediaPolicy;
use App\Services\Media\Data\StoredMedia;
use App\Services\Media\Exceptions\MediaConfigurationException;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\RestaurantMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

final class RestaurantMediaController extends Controller
{
    public function __construct(
        private readonly RestaurantMediaService $media,
        private readonly MediaPolicy $policy,
    ) {}

    public function logo(UploadRestaurantLogoRequest $request, Restaurant|string $restaurant): JsonResponse
    {
        $restaurant = $this->resolveRestaurant($restaurant);
        abort_unless($this->policy->updateRestaurantMedia($this->identity($request), $restaurant), 403);

        return $this->storePublicMedia(
            fn (UploadedFile $file) => $this->media->updateLogo($restaurant, $file),
            'logo_url',
            $request->file('file'),
        );
    }

    public function cover(UploadRestaurantCoverRequest $request, Restaurant|string $restaurant): JsonResponse
    {
        $restaurant = $this->resolveRestaurant($restaurant);
        abort_unless($this->policy->updateRestaurantMedia($this->identity($request), $restaurant), 403);

        return $this->storePublicMedia(
            fn (UploadedFile $file) => $this->media->updateCover($restaurant, $file),
            'cover_image_url',
            $request->file('file'),
        );
    }

    public function menuItemImage(
        UploadMenuItemImageRequest $request,
        Restaurant|string $restaurant,
        MenuItem|string $menuItem,
    ): JsonResponse {
        $restaurant = $this->resolveRestaurant($restaurant);
        $menuItem = $this->resolveMenuItem($restaurant, $menuItem);
        $scopedMenuItem = $restaurant->menuItems()
            ->whereKey($menuItem->getKey())
            ->firstOrFail();

        abort_unless(
            $this->policy->updateMenuItemMedia($this->identity($request), $restaurant, $scopedMenuItem),
            403,
        );

        return $this->storePublicMedia(
            fn (UploadedFile $file) => $this->media->updateMenuItemImage($restaurant, $scopedMenuItem, $file),
            'image_url',
            $request->file('file'),
        );
    }

    private function resolveRestaurant(Restaurant|string $restaurant): Restaurant
    {
        return $restaurant instanceof Restaurant
            ? $restaurant
            : Restaurant::query()->findOrFail($restaurant);
    }

    private function identity(UploadRestaurantLogoRequest|UploadRestaurantCoverRequest|UploadMenuItemImageRequest $request): SupabaseIdentity
    {
        $identity = $request->attributes->get(SupabaseIdentity::REQUEST_ATTRIBUTE);
        abort_unless($identity instanceof SupabaseIdentity, 401);

        return $identity;
    }

    private function resolveMenuItem(Restaurant $restaurant, MenuItem|string $menuItem): MenuItem
    {
        if ($menuItem instanceof MenuItem) {
            return $menuItem;
        }

        return $restaurant->menuItems()->whereKey($menuItem)->firstOrFail();
    }

    /** @param callable(UploadedFile): StoredMedia $store */
    private function storePublicMedia(callable $store, string $field, ?UploadedFile $file): JsonResponse
    {
        try {
            if (! $file instanceof UploadedFile) {
                return response()->json(['message' => 'A media file is required.'], 422);
            }

            $stored = $store($file);

            return response()->json([
                'data' => [
                    'field' => $field,
                    'url' => $stored->url,
                    'content_type' => $stored->contentType,
                    'size' => $stored->size,
                ],
            ], 201);
        } catch (MediaConfigurationException) {
            return response()->json([
                'message' => 'Media storage is not enabled or configured.',
                'code' => 'MEDIA_STORAGE_UNAVAILABLE',
            ], 503);
        } catch (MediaStorageException) {
            return response()->json([
                'message' => 'Media upload could not be completed.',
                'code' => 'MEDIA_UPLOAD_FAILED',
            ], 502);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Media upload could not be completed.',
                'code' => 'MEDIA_UPLOAD_FAILED',
            ], 502);
        }
    }
}
