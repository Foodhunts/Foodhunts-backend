<?php

namespace App\Services;

use App\Models\AppFeatureFlag;

class FeatureFlagService
{
    private const DEFAULT_FLAGS = [
        'BUY_FOR_ME' => true,
        'REFERRAL_CODE' => true,
    ];

    public function all(): array
    {
        $flags = self::DEFAULT_FLAGS;

        try {
            foreach (AppFeatureFlag::query()->get(['key', 'enabled']) as $flag) {
                $key = strtoupper(trim((string) $flag->key));

                if (array_key_exists($key, $flags)) {
                    $flags[$key] = (bool) $flag->enabled;
                }
            }
        } catch (\Throwable) {
            return $flags;
        }

        return $flags;
    }

    public function enabled(string $key): bool
    {
        return (bool) ($this->all()[strtoupper($key)] ?? false);
    }
}
