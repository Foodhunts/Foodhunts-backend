<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class MediaMigrationManifest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'source_url',
        'source_provider',
        'source_path',
        'destination_key',
        'destination_url',
        'model_type',
        'model_id',
        'field_name',
        'status',
        'checksum',
        'mime_type',
        'size',
        'error_message',
        'migrated_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'migrated_at' => 'datetime',
        ];
    }
}
