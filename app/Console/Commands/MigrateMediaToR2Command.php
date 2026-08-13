<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaMigrationManifest;
use App\Services\Media\MediaConfigurationValidator;
use App\Services\Media\PublicMediaMigrationService;
use Illuminate\Console\Command;

final class MigrateMediaToR2Command extends Command
{
    protected $signature = 'media:migrate-to-r2
        {--dry-run : Preview records without downloading or uploading}
        {--entity= : Limit to restaurant or menu_item}
        {--id= : Limit to one entity UUID}
        {--limit= : Maximum number of media fields to inspect}
        {--batch-size=25 : Number of records to process per batch}
        {--resume : Retry failed or interrupted manifest entries}
        {--verify-only : Verify completed R2 objects without changing records}
        {--confirm : Confirm that this is an authorized production migration}';

    protected $description = 'Migrate supported public Supabase media to Cloudflare R2';

    public function handle(
        PublicMediaMigrationService $migration,
        MediaConfigurationValidator $configuration,
    ): int {
        $entity = $this->option('entity');
        if ($entity !== null && ! in_array($entity, ['restaurant', 'menu', 'menu_item', 'app_ad'], true)) {
            $this->error('The entity must be restaurant, menu, menu_item or app_ad.');

            return self::FAILURE;
        }

        $limit = $this->positiveIntOption('limit');
        $batchSize = $this->positiveIntOption('batch-size') ?? 25;
        if ($batchSize < 1) {
            $this->error('The batch size must be greater than zero.');

            return self::FAILURE;
        }

        if ($this->option('verify-only')) {
            return $this->verify($entity, $this->option('id'), $limit, $configuration);
        }

        $candidates = $migration->candidates($entity, $this->option('id'), $limit);
        $this->displayPlan($candidates);

        if (! $this->option('confirm') || $this->option('dry-run')) {
            $this->comment('Dry run only. Re-run with --confirm to migrate these records.');

            return self::SUCCESS;
        }

        try {
            $configuration->requireR2(true);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $counts = [
            MediaMigrationManifest::STATUS_COMPLETED => 0,
            MediaMigrationManifest::STATUS_FAILED => 0,
            MediaMigrationManifest::STATUS_SKIPPED => 0,
        ];

        foreach (array_chunk($candidates, $batchSize) as $batch) {
            foreach ($batch as $candidate) {
                $status = $migration->migrate($candidate, (bool) $this->option('resume'));
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        $this->table(['Status', 'Count'], array_map(
            static fn (string $status, int $count): array => [$status, $count],
            array_keys($counts),
            array_values($counts),
        ));

        return $counts[MediaMigrationManifest::STATUS_FAILED] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function verify(?string $entity, ?string $id, ?int $limit, MediaConfigurationValidator $configuration): int
    {
        try {
            $configuration->requireR2(true);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $query = MediaMigrationManifest::query()
            ->where('status', MediaMigrationManifest::STATUS_COMPLETED)
            ->when($entity !== null, fn ($builder) => $builder->where('model_type', $entity))
            ->when($id !== null, fn ($builder) => $builder->where('model_id', $id))
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $failed = 0;
        foreach ($query->get() as $manifest) {
            $exists = is_string($manifest->destination_key)
                && app(\App\Services\Media\MediaStorageService::class)->exists($manifest->destination_key)
                && app(\App\Services\Media\MediaStorageService::class)->size($manifest->destination_key) === $manifest->size;
            $this->line(sprintf('%s %s', $manifest->id, $exists ? 'verified' : 'failed'));
            $failed += $exists ? 0 : 1;
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<array{modelType:string,field:string}> $candidates */
    private function displayPlan(array $candidates): void
    {
        $this->line('Records found: '.count($candidates));
        $this->line('Estimated objects: '.count($candidates));
        $this->line('Target bucket: '.(string) config('filesystems.disks.r2.bucket'));
        $this->line('Public URL: '.rtrim((string) config('media.public_base_url'), '/'));
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1) {
            throw new \InvalidArgumentException("The {$name} option must be a positive integer.");
        }

        return $parsed;
    }
}
