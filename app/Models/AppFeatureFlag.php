<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppFeatureFlag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'key',
        'enabled',
        'description',
    ];

    protected $casts = [
        'enabled' => 'bool',
    ];
}
