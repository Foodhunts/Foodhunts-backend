<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaCategory;
use App\Models\AppAd;
use App\Models\MediaMigrationManifest;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\Media\Data\MediaObjectContext;
use App\Services\Media\Exceptions\MediaMigrationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PublicMediaMigrationService
{
    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly SupabaseMediaDownloader $downloader,
    ) {}

    /** @return list<array{model:Model,modelType:string,field:string,sourceUrl:string,category:MediaCategory,context:MediaObjectContext}> */
    public function candidates(?string $entity = null, ?string $id = null, ?int $limit = null): array
    {
        $candidates = [];

        if ($entity === null || $entity === 'restaurant') {
            $restaurantQuery = Restaurant::query()
                ->where(function ($query): void {
                    $query->whereNotNull('logo_url')->where('logo_url', '!=', '')
                        ->orWhere(function ($nested): void {
                            $nested->whereNotNull('header_image_url')->where('header_image_url', '!=', '');
                        });
                })
                ->when($id !== null, fn ($query) => $query->whereKey($id))
                ->orderBy('id');

            foreach ($restaurantQuery->get() as $restaurant) {
                if ($this->isAbsoluteHttpUrl($restaurant->logo_url)) {
                    $candidates[] = $this->candidate(
                        $restaurant,
                        'restaurant',
                        'logo_url',
                        $restaurant->logo_url,
                        MediaCategory::RestaurantLogo,
                        MediaObjectContext::restaurant((string) $restaurant->getKey()),
                    );
                }
                if ($this->isAbsoluteHttpUrl($restaurant->header_image_url)) {
                    $candidates[] = $this->candidate(
                        $restaurant,
                        'restaurant',
                        'header_image_url',
                        $restaurant->header_image_url,
                        MediaCategory::RestaurantHeader,
                        MediaObjectContext::restaurant((string) $restaurant->getKey()),
                    );
                }
            }
        }

        if ($entity === null || $entity === 'menu') {
            $menuQuery = Menu::query()
                ->whereNotNull('menu_image_url')
                ->where('menu_image_url', '!=', '')
                ->when($id !== null, fn ($query) => $query->whereKey($id))
                ->orderBy('id');

            foreach ($menuQuery->get() as $menu) {
                if ($this->isAbsoluteHttpUrl($menu->menu_image_url)) {
                    $candidates[] = $this->candidate(
                        $menu,
                        'menu',
                        'menu_image_url',
                        $menu->menu_image_url,
                        MediaCategory::MenuImage,
                        MediaObjectContext::menu(
                            (string) $menu->restaurant_id,
                            (string) $menu->getKey(),
                        ),
                    );
                }
            }
        }

        if ($entity === null || $entity === 'menu_item') {
            $menuItemQuery = MenuItem::query()
                ->whereNotNull('image_url')
                ->where('image_url', '!=', '')
                ->when($id !== null, fn ($query) => $query->whereKey($id))
                ->orderBy('id');

            foreach ($menuItemQuery->get() as $menuItem) {
                if ($this->isAbsoluteHttpUrl($menuItem->image_url)) {
                    $candidates[] = $this->candidate(
                        $menuItem,
                        'menu_item',
                        'image_url',
                        $menuItem->image_url,
                        MediaCategory::MenuItemImage,
                        MediaObjectContext::menuItem(
                            (string) $menuItem->restaurant_id,
                            (string) $menuItem->getKey(),
                        ),
                    );
                }
            }
        }

        if ($entity === null || $entity === 'app_ad') {
            $adQuery = AppAd::query()
                ->whereNotNull('image_url')
                ->where('image_url', '!=', '')
                ->when($id !== null, fn ($query) => $query->whereKey($id))
                ->orderBy('id');

            foreach ($adQuery->get() as $ad) {
                if ($this->isAbsoluteHttpUrl($ad->image_url)) {
                    $candidates[] = $this->candidate(
                        $ad,
                        'app_ad',
                        'image_url',
                        $ad->image_url,
                        MediaCategory::PromotionImage,
                        MediaObjectContext::promotion((string) $ad->getKey()),
                    );
                }
            }
        }

        return $limit === null ? $candidates : array_slice($candidates, 0, max(0, $limit));
    }

    /** @param array{model:Model,modelType:string,field:string,sourceUrl:string,category:MediaCategory,context:MediaObjectContext} $candidate */
    public function migrate(array $candidate, bool $resume = false): string
    {
        $sourceUrl = $candidate['sourceUrl'];
        $existing = MediaMigrationManifest::query()
            ->where('model_type', $candidate['modelType'])
            ->where('model_id', $candidate['model']->getKey())
            ->where('field_name', $candidate['field'])
            ->where('source_url', $sourceUrl)
            ->latest('created_at')
            ->first();

        if ($existing?->status === MediaMigrationManifest::STATUS_COMPLETED) {
            return MediaMigrationManifest::STATUS_SKIPPED;
        }

        if ($existing !== null && ! $resume && in_array($existing->status, [
            MediaMigrationManifest::STATUS_FAILED,
            MediaMigrationManifest::STATUS_PROCESSING,
        ], true)) {
            return MediaMigrationManifest::STATUS_SKIPPED;
        }

        $manifest = $existing ?? new MediaMigrationManifest([
            'source_url' => $sourceUrl,
            'source_provider' => 'supabase',
            'model_type' => $candidate['modelType'],
            'model_id' => $candidate['model']->getKey(),
            'field_name' => $candidate['field'],
        ]);

        $manifest->fill(['status' => MediaMigrationManifest::STATUS_PROCESSING, 'error_message' => null]);
        $manifest->save();

        $downloaded = null;
        try {
            if ($this->isR2Url($sourceUrl)) {
                $manifest->source_provider = 'r2';
                return $this->skip($manifest, 'The media URL already uses the configured R2 public URL.');
            }

            $source = $this->downloader->source($sourceUrl);
            $manifest->source_path = $source['bucket'].'/'.$source['path'];
            $manifest->save();

            $downloaded = $this->downloader->download($sourceUrl, $candidate['category']->maxBytes());

            $stored = $this->storage->store(
                $candidate['category'],
                $candidate['context'],
                new \Illuminate\Http\UploadedFile(
                    $downloaded->temporaryPath,
                    basename($downloaded->sourcePath),
                    $downloaded->contentType,
                    UPLOAD_ERR_OK,
                    true,
                ),
            );

            if (! is_string($stored->url)
                || ! $this->storage->exists($stored->objectKey)
                || $stored->size !== $downloaded->size
                || $this->storage->mimeType($stored->objectKey) !== $downloaded->contentType) {
                throw new MediaMigrationException('The R2 object failed post-upload verification.');
            }

            DB::transaction(function () use ($candidate, $manifest, $stored, $downloaded): void {
                $candidate['model']->updateOrFail([$candidate['field'] => $stored->url]);
                $manifest->fill([
                    'destination_key' => $stored->objectKey,
                    'destination_url' => $stored->url,
                    'checksum' => $downloaded->checksum,
                    'mime_type' => $downloaded->contentType,
                    'size' => $downloaded->size,
                    'status' => MediaMigrationManifest::STATUS_COMPLETED,
                    'migrated_at' => now(),
                    'error_message' => null,
                ]);
                $manifest->save();
            });

            return MediaMigrationManifest::STATUS_COMPLETED;
        } catch (Throwable $exception) {
            $manifest->update([
                'status' => MediaMigrationManifest::STATUS_FAILED,
                'error_message' => Str::limit($exception->getMessage(), 2000, ''),
            ]);

            return MediaMigrationManifest::STATUS_FAILED;
        } finally {
            if ($downloaded !== null) {
                @unlink($downloaded->temporaryPath);
            }
        }
    }

    public function rollback(MediaMigrationManifest $manifest): string
    {
        if ($manifest->status !== MediaMigrationManifest::STATUS_COMPLETED) {
            return 'skipped';
        }

        $model = match ($manifest->model_type) {
            'restaurant' => Restaurant::query()->find($manifest->model_id),
            'menu' => Menu::query()->find($manifest->model_id),
            'menu_item' => MenuItem::query()->find($manifest->model_id),
            'app_ad' => AppAd::query()->find($manifest->model_id),
            default => null,
        };

        if (! $model instanceof Model) {
            return 'skipped';
        }

        if ($model->getAttribute($manifest->field_name) !== $manifest->destination_url) {
            return 'conflict';
        }

        $model->updateOrFail([$manifest->field_name => $manifest->source_url]);

        return 'rolled_back';
    }

    /** @return array{model:Model,modelType:string,field:string,sourceUrl:string,category:MediaCategory,context:MediaObjectContext} */
    private function candidate(Model $model, string $modelType, string $field, string $sourceUrl, MediaCategory $category, MediaObjectContext $context): array
    {
        return compact('model', 'modelType', 'field', 'sourceUrl', 'category', 'context');
    }

    private function isAbsoluteHttpUrl(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        return str_starts_with(strtolower(ltrim(trim($value))), 'http');
    }

    private function isR2Url(string $url): bool
    {
        $configured = rtrim((string) config('media.public_base_url'), '/');

        return $configured !== '' && str_starts_with(trim($url), $configured.'/');
    }

    private function skip(MediaMigrationManifest $manifest, string $reason): string
    {
        $manifest->update([
            'source_provider' => $manifest->source_provider,
            'status' => MediaMigrationManifest::STATUS_SKIPPED,
            'error_message' => $reason,
        ]);

        return MediaMigrationManifest::STATUS_SKIPPED;
    }
}
