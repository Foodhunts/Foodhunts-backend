<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Read model over the Supabase-owned public.app_ads table so the R2 media
 * migration can rewrite ad image URLs through the same pipeline.
 */
class AppAd extends Model
{
    use HasUuids;

    protected $table = 'app_ads';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'ad_type',
        'text_content',
        'image_url',
        'image_path',
        'placement',
        'is_active',
        'start_at',
        'end_at',
        'display_duration_seconds',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'display_duration_seconds' => 'integer',
        'sort_order' => 'integer',
    ];
}
