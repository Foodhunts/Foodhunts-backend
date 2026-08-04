<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaCategory;
use App\Services\Media\Exceptions\PrivateMediaUrlException;

final class MediaUrlResolver
{
    public function resolve(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $parts = parse_url($value);

        if (is_array($parts) && isset($parts['scheme'])) {
            if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
                return null;
            }

            return $value;
        }

        if (MediaObjectKeyGenerator::isPrivate($value) || ! MediaObjectKeyGenerator::isAllowed($value)) {
            return null;
        }

        $baseUrl = config('media.public_base_url');
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            return null;
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $value)));

        return rtrim($baseUrl, '/').'/'.$encodedPath;
    }

    public function resolveForCategory(MediaCategory $category, string $objectKey): string
    {
        if (! $category->allowsPermanentUrl() || MediaObjectKeyGenerator::isPrivate($objectKey)) {
            throw new PrivateMediaUrlException('Private media cannot receive a permanent public URL.');
        }

        $url = $this->resolve($objectKey);
        if ($url === null) {
            throw new PrivateMediaUrlException('A public media URL is not configured.');
        }

        return $url;
    }
}
