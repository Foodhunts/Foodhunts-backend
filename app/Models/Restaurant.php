<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Restaurant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'description',
        'phone_number',
        'logo_url',
        'cover_image_url',
        'is_active',
        'kyc_status',
        'payout_account_name',
        'payout_account_number',
        'paystack_recipient_code',
        'paystack_subaccount_code',
        'kyc_documents',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'bool',
        'kyc_documents' => 'array',
        'metadata' => 'array',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function menus()
    {
        return $this->hasMany(Menu::class);
    }

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
