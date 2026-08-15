<?php

use App\Models\User;
use App\Services\Media\Exceptions\MediaConfigurationException;
use App\Services\Media\MediaConfigurationValidator;
use App\Services\ReferralCodeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('foodhunts:heartbeat', function (): void {
    $this->comment('Foodhunts backend is ready.');
})->purpose('Print a backend readiness message');

Artisan::command('foodhunts:test-r2', function (): int {
    try {
        app(MediaConfigurationValidator::class)->requireR2(false);
    } catch (MediaConfigurationException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $disk = Storage::disk('r2');
    $path = 'codex-verification/'.Str::uuid().'.txt';
    $contents = 'Foodhunts R2 verification object.';
    $uploaded = false;

    try {
        if (! $disk->put($path, $contents, ['ContentType' => 'text/plain'])) {
            $this->error('R2 upload failed.');

            return 1;
        }

        $uploaded = true;

        $existsAfterUpload = $disk->exists($path);
        $readMatches = $disk->get($path) === $contents;
        $size = $disk->size($path);
        $mimeType = $disk->mimeType($path);

        if (! $existsAfterUpload || ! $readMatches || $size !== strlen($contents)) {
            $this->error('R2 read or inspection failed.');

            return 1;
        }

        $this->info('R2 upload, read, and inspection succeeded.');
        $this->line("Size: {$size} bytes; MIME type: {$mimeType}");

        return 0;
    } finally {
        if ($uploaded) {
            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
                $this->warn('R2 test-object cleanup could not be confirmed.');
            }
        }
    }
})->purpose('Verify the dedicated Cloudflare R2 disk with a temporary object');

Artisan::command('foodhunts:backfill-referral-codes', function (): void {
    $service = app(ReferralCodeService::class);
    $updated = 0;

    User::query()
        ->where(function ($query): void {
            $query->whereNull('referral_code')
                ->orWhere('referral_code', '');
        })
        ->chunkById(100, function ($users) use ($service, &$updated): void {
            foreach ($users as $user) {
                $user->forceFill(['referral_code' => $service->generate()])->save();
                $updated++;
            }
        });

    $this->info("Backfilled referral codes for {$updated} users.");
})->purpose('Backfill missing referral codes for existing users');

// Transactional push outbox drain. Runs every minute; each pass claims a
// bounded batch of pending rows (single-winner) and sends via Expo. Safe to
// run concurrently; `withoutOverlapping` prevents a second pass stacking up
// behind a slow one.
Schedule::command('push:send-pending --limit=200')
    ->everyMinute()
    ->withoutOverlapping();
