<?php

declare(strict_types=1);

namespace App\Services\Media\Data;

final readonly class StoredMedia
{
    public function __construct(
        public string $disk,
        public string $objectKey,
        public string $contentType,
        public int $size,
        public bool $isPublic,
        public ?string $url,
    ) {}
}
