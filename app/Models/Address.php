<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    use HasFactory, HasUuids;

    /**
     * The production table is the shared Supabase `addresses` table, whose
     * schema uses `name` (not `label`), has no `country` column, and has a
     * `created_at` (default now()) but no `updated_at`. Keep this model
     * aligned to that schema so inserts/updates don't fail on columns that
     * don't exist.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'name',
        'street_address',
        'city',
        'state',
        'postal_code',
        'latitude',
        'longitude',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'bool',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
