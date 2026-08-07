<?php

namespace App\Services;

use App\Models\User;

class ReferralCodeService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        do {
            $code = 'FH'.$this->randomCharacters(6);
        } while (User::query()->where('referral_code', $code)->exists());

        return $code;
    }

    private function randomCharacters(int $length): string
    {
        $characters = '';
        $alphabetLength = strlen(self::ALPHABET);

        for ($index = 0; $index < $length; $index++) {
            $characters .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $characters;
    }
}
