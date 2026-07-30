<?php

namespace App\Exceptions;

use RuntimeException;

class SupabaseAuthUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Supabase authentication is temporarily unavailable.');
    }
}
