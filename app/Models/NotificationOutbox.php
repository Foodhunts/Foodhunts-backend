<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationOutbox extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    public const MAX_ATTEMPTS = 5;

    protected $table = 'notification_outbox';

    protected $fillable = [
        'user_id',
        'event',
        'title',
        'body',
        'data',
        'dedup_key',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'attempts' => 'int',
        'available_at' => 'datetime',
        'locked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
