<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MediaMigrationManifest;
use Illuminate\Console\Command;

final class RollbackR2MediaMigrationCommand extends Command
{
    protected $signature = 'media:rollback-r2-migration
        {--entity= : Limit to restaurant, menu, menu_item or app_ad}
        {--id= : Limit to one entity UUID}
        {--limit= : Maximum number of manifest entries}
        {--confirm : Confirm URL restoration}';

    protected $description = 'Restore Supabase URLs recorded by completed R2 migrations';

    public function handle(\App\Services\Media\PublicMediaMigrationService $migration): int
    {
        if (! $this->option('confirm')) {
            $this->comment('Dry run only. Re-run with --confirm to restore URLs.');
            return self::SUCCESS;
        }

        $query = MediaMigrationManifest::query()
            ->where('status', MediaMigrationManifest::STATUS_COMPLETED)
            ->when($this->option('entity'), fn ($builder, $entity) => $builder->where('model_type', $entity))
            ->when($this->option('id'), fn ($builder, $id) => $builder->where('model_id', $id))
            ->orderBy('id');

        if (($limit = $this->option('limit')) !== null) {
            $parsed = filter_var($limit, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 1) {
                $this->error('The limit must be a positive integer.');
                return self::FAILURE;
            }
            $query->limit($parsed);
        }

        $counts = ['rolled_back' => 0, 'conflict' => 0, 'skipped' => 0];
        foreach ($query->get() as $manifest) {
            $status = $migration->rollback($manifest);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        $this->table(['Status', 'Count'], array_map(
            static fn (string $status, int $count): array => [$status, $count],
            array_keys($counts),
            array_values($counts),
        ));

        return self::SUCCESS;
    }
}
