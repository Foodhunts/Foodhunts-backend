<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The verified identity supplied by Supabase Auth for routes that do not need
 * a corresponding Laravel users-table record.
 */
final readonly class SupabaseIdentity
{
    public const REQUEST_ATTRIBUTE = 'supabase.identity';

    public function __construct(
        public string $id,
        public ?string $email,
    ) {}
}
