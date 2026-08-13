<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiderDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'rider_id',
        'document_type',
        'storage_key',
        'mime',
        'size_bytes',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function rider()
    {
        return $this->belongsTo(Rider::class);
    }
}
