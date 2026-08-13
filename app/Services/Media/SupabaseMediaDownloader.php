<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Media\Data\DownloadedMedia;
use App\Services\Media\Exceptions\MediaMigrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SupabaseMediaDownloader
{
    public function download(string $sourceUrl, int $maxBytes): DownloadedMedia
    {
        $source = $this->source($sourceUrl);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'foodhunts-media-');
        if ($temporaryPath === false) {
            throw new MediaMigrationException('Supabase media could not be written to a temporary file.');
        }

        try {
            $response = $this->request()->withOptions(['sink' => $temporaryPath])->get($source['url']);
        } catch (Throwable $exception) {
            @unlink($temporaryPath);
            throw new MediaMigrationException('Supabase media download failed.', 0, $exception);
        }

        if (! $response->successful()) {
            @unlink($temporaryPath);
            throw new MediaMigrationException('Supabase media object could not be downloaded.');
        }

        $size = filesize($temporaryPath);
        if (! is_int($size) || $size <= 0 || $size > $maxBytes) {
            @unlink($temporaryPath);
            throw new MediaMigrationException('Supabase media could not be written to a temporary file.');
        }

        try {
            $contentType = $this->contentType($temporaryPath);
            $this->validateExtension($source['path'], $contentType);
            $this->validateImage($temporaryPath, $contentType);
            $checksum = hash_file('sha256', $temporaryPath);

            if (! is_string($checksum)) {
                throw new MediaMigrationException('Supabase media metadata could not be read.');
            }

            return new DownloadedMedia($temporaryPath, $source['path'], $contentType, $size, $checksum);
        } catch (Throwable $exception) {
            @unlink($temporaryPath);
            if ($exception instanceof MediaMigrationException) {
                throw $exception;
            }

            throw new MediaMigrationException('Supabase media validation failed.', 0, $exception);
        }
    }

    /** @return array{url:string,bucket:string,path:string} */
    public function source(string $sourceUrl): array
    {
        $parts = parse_url(trim($sourceUrl));
        $configured = parse_url((string) config('supabase.url'));

        if (! is_array($parts) || ! is_array($configured)
            || ! isset($parts['scheme'], $parts['host'], $parts['path'], $configured['host'])
            || strtolower((string) $parts['host']) !== strtolower((string) $configured['host'])) {
            throw new MediaMigrationException('The media URL is not from the configured Supabase project.');
        }

        $path = rawurldecode((string) $parts['path']);
        if (! preg_match('#^/storage/v1/object/(?:public|sign|authenticated)/([^/]+)/(.+)$#', $path, $matches)) {
            throw new MediaMigrationException('The Supabase media URL has no supported storage path.');
        }

        return [
            'url' => trim($sourceUrl),
            'bucket' => $matches[1],
            'path' => $matches[2],
        ];
    }

    private function request(): PendingRequest
    {
        return Http::accept('*/*')
            ->connectTimeout((int) config('supabase.auth_connect_timeout', 3))
            ->timeout((int) config('supabase.auth_timeout', 30));
    }

    private function contentType(string $path): string
    {
        $contentType = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
        $contentType = $contentType === 'image/jpg' ? 'image/jpeg' : $contentType;

        if (! is_string($contentType) || ! in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new MediaMigrationException('The Supabase media type is not supported.');
        }

        return $contentType;
    }

    private function validateExtension(string $sourcePath, string $contentType): void
    {
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $expected = match ($contentType) {
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            default => [],
        };

        if (! in_array($extension, $expected, true)) {
            throw new MediaMigrationException('The Supabase media extension does not match its content type.');
        }
    }

    private function validateImage(string $path, string $contentType): void
    {
        if (@getimagesize($path) === false) {
            throw new MediaMigrationException("The {$contentType} media is corrupted or incomplete.");
        }
    }
}
