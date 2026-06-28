<?php

namespace Database\Seeders;

use App\Models\AppFeatureFlag;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ([
            'BUY_FOR_ME' => 'Enable buy-for-me shareable payment links.',
            'REFERRAL_CODE' => 'Enable referral codes and referral rewards.',
        ] as $key => $description) {
            AppFeatureFlag::query()->updateOrCreate(
                ['key' => $key],
                ['enabled' => true, 'description' => $description]
            );
        }
    }
}
