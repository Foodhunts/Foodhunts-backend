<?php

declare(strict_types=1);

namespace App\Services\Media\Data;

final readonly class DownloadedMedia
{
    public function __construct(
        public string $temporaryPath,
        public string $sourcePath,
        public string $contentType,
        public int $size,
        public string $checksum,
    ) {}
}
