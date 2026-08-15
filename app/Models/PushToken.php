<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PushToken extends Model
{
    use HasFactory, HasUuids;

    /**
     * The production `push_tokens` table is the shared Supabase table, whose
     * schema uses `expo_push_token` + `device_id` (not `token`) and carries
     * failure/health columns. Keep this model aligned to that schema.
     */
    protected $fillable = [
        'user_id',
        'device_id',
        'expo_push_token',
        'platform',
        'last_seen_at',
        'is_active',
        'last_success_at',
        'last_failure_at',
        'failure_count',
        'error_code',
        'error_message',
        'app_version',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'failure_count' => 'int',
        'last_seen_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
