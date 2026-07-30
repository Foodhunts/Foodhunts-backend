<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidSupabaseTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid Supabase authentication token.');
    }
}
