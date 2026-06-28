<?php

use App\Models\User;
use App\Services\ReferralCodeService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('foodhunts:heartbeat', function (): void {
    $this->comment('Foodhunts backend is ready.');
})->purpose('Print a backend readiness message');

Artisan::command('foodhunts:backfill-referral-codes', function (): void {
    $service = app(ReferralCodeService::class);
    $updated = 0;

    User::query()
        ->where(function ($query): void {
            $query->whereNull('referral_code')
                ->orWhere('referral_code', '');
        })
        ->chunkById(100, function ($users) use ($service, &$updated): void {
            foreach ($users as $user) {
                $user->forceFill(['referral_code' => $service->generate()])->save();
                $updated++;
            }
        });

    $this->info("Backfilled referral codes for {$updated} users.");
})->purpose('Backfill missing referral codes for existing users');
