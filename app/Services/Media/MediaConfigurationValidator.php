<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Media\Exceptions\MediaConfigurationException;

final class MediaConfigurationValidator
{
    public function driver(): string
    {
        $driver = strtolower(trim((string) config('media.driver', 'legacy')));

        if (! in_array($driver, ['legacy', 'r2'], true)) {
            throw new MediaConfigurationException(
                'MEDIA_STORAGE_DRIVER must be either legacy or r2.',
            );
        }

        return $driver;
    }

    public function validate(bool $requiresPublicUrl = false): void
    {
        if ($this->driver() === 'legacy') {
            return;
        }

        $disk = (array) config('filesystems.disks.r2', []);
        $missing = [];

        foreach ([
            'access key' => $disk['key'] ?? null,
            'secret' => $disk['secret'] ?? null,
            'bucket' => $disk['bucket'] ?? null,
            'endpoint' => $disk['endpoint'] ?? null,
            'region' => $disk['region'] ?? null,
        ] as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            throw new MediaConfigurationException(
                'R2 media configuration is missing: '.implode(', ', $missing).'.',
            );
        }

        $this->assertHttpUrl((string) $disk['endpoint'], 'R2 endpoint');

        $publicBaseUrl = config('media.public_base_url');
        if ($requiresPublicUrl && (! is_string($publicBaseUrl) || trim($publicBaseUrl) === '')) {
            throw new MediaConfigurationException(
                'R2 public base URL is required for public media.',
            );
        }

        if (is_string($publicBaseUrl) && trim($publicBaseUrl) !== '') {
            $this->assertHttpUrl($publicBaseUrl, 'R2 public base URL');
        }
    }

    public function requireR2(bool $requiresPublicUrl = false): void
    {
        if ($this->driver() !== 'r2') {
            throw new MediaConfigurationException(
                'R2 media storage is disabled while MEDIA_STORAGE_DRIVER is legacy.',
            );
        }

        $this->validate($requiresPublicUrl);
    }

    private function assertHttpUrl(string $value, string $label): void
    {
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (! filter_var($value, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new MediaConfigurationException("{$label} must be a valid HTTP or HTTPS URL.");
        }
    }
}
