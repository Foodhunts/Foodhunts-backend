<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\Media\Data\MediaObjectContext;
use App\Services\Media\Data\StoredMedia;
use App\Services\Media\Exceptions\MediaStorageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RestaurantMediaService
{
    public function __construct(private readonly MediaStorageService $storage) {}

    public function updateLogo(Restaurant $restaurant, UploadedFile $file): StoredMedia
    {
        return $this->storeAndUpdate(
            $restaurant,
            MediaCategory::RestaurantLogo,
            MediaObjectContext::restaurant((string) $restaurant->getKey()),
            $file,
            'logo_url',
        );
    }

    public function updateCover(Restaurant $restaurant, UploadedFile $file): StoredMedia
    {
        return $this->storeAndUpdate(
            $restaurant,
            MediaCategory::RestaurantCover,
            MediaObjectContext::restaurant((string) $restaurant->getKey()),
            $file,
            'cover_image_url',
        );
    }

    public function updateMenuItemImage(
        Restaurant $restaurant,
        MenuItem $menuItem,
        UploadedFile $file,
    ): StoredMedia {
        return $this->storeAndUpdate(
            $menuItem,
            MediaCategory::MenuItemImage,
            MediaObjectContext::menuItem(
                (string) $restaurant->getKey(),
                (string) $menuItem->getKey(),
            ),
            $file,
            'image_url',
        );
    }

    private function storeAndUpdate(
        object $model,
        MediaCategory $category,
        MediaObjectContext $context,
        UploadedFile $file,
        string $field,
    ): StoredMedia {
        $stored = $this->storage->store($category, $context, $file);
        if (! is_string($stored->url)) {
            throw new MediaStorageException('Public media storage returned no public URL.');
        }

        try {
            DB::transaction(function () use ($model, $field, $stored): void {
                if (! $model->update([$field => $stored->url])) {
                    throw new MediaStorageException('The media reference could not be saved.');
                }
            });
        } catch (MediaStorageException $exception) {
            $this->logDatabaseFailure($category, $model, $stored, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logDatabaseFailure($category, $model, $stored, $exception);
            throw new MediaStorageException('The media reference could not be saved.', 0, $exception);
        }

        return $stored;
    }

    private function logDatabaseFailure(
        MediaCategory $category,
        object $model,
        StoredMedia $stored,
        Throwable $exception,
    ): void {
        Log::warning('Media database update failed after storage verification.', [
            'category' => $category->value,
            'entity_type' => $model::class,
            'entity_id' => method_exists($model, 'getKey') ? (string) $model->getKey() : 'unknown',
            'object_key' => $stored->objectKey,
            'error_class' => $exception::class,
        ]);
    }
}
