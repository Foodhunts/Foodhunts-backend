<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaCategory;
use App\Services\Media\Data\MediaObjectContext;
use App\Services\Media\Data\StoredMedia;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\Exceptions\PrivateMediaUrlException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class MediaStorageService
{
    public function __construct(
        private readonly MediaConfigurationValidator $configuration,
        private readonly MediaObjectKeyGenerator $keyGenerator,
        private readonly MediaUrlResolver $urlResolver,
    ) {}

    public function store(
        MediaCategory $category,
        MediaObjectContext $context,
        UploadedFile $file,
    ): StoredMedia {
        $this->configuration->requireR2($category->isPublic());

        $contentType = $file->getMimeType();
        if (! is_string($contentType) || ! in_array($contentType, $category->allowedContentTypes(), true)) {
            throw new MediaStorageException('The uploaded media content type is not allowed.');
        }

        $size = $file->getSize();
        if (! is_int($size) || $size <= 0 || $size > $category->maxBytes()) {
            throw new MediaStorageException('The uploaded media size is invalid or exceeds the category limit.');
        }

        $objectKey = $this->keyGenerator->generate($category, $context, $contentType);
        try {
            $disk = Storage::disk('r2');
            $stored = $disk->putFileAs(
                dirname($objectKey),
                $file,
                basename($objectKey),
                ['ContentType' => $contentType],
            );

            if ($stored === false || ! $disk->exists($objectKey)) {
                throw new MediaStorageException('R2 media upload could not be verified.');
            }

            $storedSize = $disk->size($objectKey);
            if ($storedSize < 0) {
                throw new MediaStorageException('R2 media size could not be verified.');
            }

            return new StoredMedia(
                disk: 'r2',
                objectKey: $objectKey,
                contentType: $contentType,
                size: $storedSize,
                isPublic: $category->isPublic(),
                url: $category->isPublic()
                    ? $this->urlResolver->resolveForCategory($category, $objectKey)
                    : null,
            );
        } catch (MediaStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 media storage failed.', 0, $exception);
        }
    }

    public function exists(string $objectKey): bool
    {
        $this->configuration->requireR2(false);
        $this->keyGenerator->assertAllowed($objectKey);

        try {
            return Storage::disk('r2')->exists($objectKey);
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 media existence check failed.', 0, $exception);
        }
    }

    public function size(string $objectKey): int
    {
        $this->configuration->requireR2(false);
        $this->keyGenerator->assertAllowed($objectKey);

        try {
            return Storage::disk('r2')->size($objectKey);
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 media size check failed.', 0, $exception);
        }
    }

    public function delete(string $objectKey): bool
    {
        $this->configuration->requireR2(false);
        $this->keyGenerator->assertAllowed($objectKey);
        $disk = Storage::disk('r2');

        try {
            if (! $disk->exists($objectKey)) {
                return false;
            }

            if (! $disk->delete($objectKey)) {
                throw new MediaStorageException('R2 media deletion failed.');
            }

            return true;
        } catch (MediaStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 media deletion failed.', 0, $exception);
        }
    }

    public function publicUrl(MediaCategory $category, string $objectKey): string
    {
        $this->configuration->requireR2(true);
        $this->keyGenerator->assertAllowed($objectKey);

        return $this->urlResolver->resolveForCategory($category, $objectKey);
    }

    public function temporaryPrivateUrl(
        string $objectKey,
        ?int $minutes = null,
    ): string {
        $this->configuration->requireR2(false);
        $this->keyGenerator->assertAllowed($objectKey);

        if (! MediaObjectKeyGenerator::isPrivate($objectKey)) {
            throw new PrivateMediaUrlException('Temporary URLs are only available for private media.');
        }

        $defaultMinutes = max(1, (int) config('media.private_url_ttl_minutes', 10));
        $requestedMinutes = $minutes ?? $defaultMinutes;
        if ($requestedMinutes < 1 || $requestedMinutes > 15) {
            throw new PrivateMediaUrlException('Private media URL expiry must be between 1 and 15 minutes.');
        }

        try {
            return Storage::disk('r2')->temporaryUrl(
                $objectKey,
                now()->addMinutes($requestedMinutes),
            );
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 private media URL generation failed.', 0, $exception);
        }
    }
}
