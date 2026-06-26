<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'menu_id',
        'restaurant_id',
        'name',
        'description',
        'price',
        'is_available',
        'image_url',
        'metadata',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_available' => 'bool',
        'metadata' => 'array',
    ];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
