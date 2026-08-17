<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\ReferralCodeService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'role',
        'referral_code',
        'referred_by_user_id',
        'referred_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'referred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->referral_code) {
                $user->referral_code = app(ReferralCodeService::class)->generate();
            }
        });
    }

    public function restaurant()
    {
        return $this->hasOne(Restaurant::class, 'owner_id');
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_user_id');
    }

    public function referralRewards()
    {
        return $this->hasMany(ReferralReward::class, 'referrer_user_id');
    }

    public function buyForMeRequests()
    {
        return $this->hasMany(BuyForMeRequest::class, 'requester_user_id');
    }

    public function pushTokens()
    {
        return $this->hasMany(PushToken::class);
    }

    public function platformReview()
    {
        return $this->hasOne(PlatformReview::class);
    }
}
