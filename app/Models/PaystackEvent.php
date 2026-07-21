<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaystackEvent extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'event', 'reference', 'payload', 'status', 'error'];
    protected $casts = ['payload' => 'array'];
}
