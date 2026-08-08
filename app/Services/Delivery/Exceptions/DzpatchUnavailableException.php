<?php

namespace App\Services\Delivery\Exceptions;

use RuntimeException;
use Throwable;

/**
 * No verdict was received from Dzpatch.
 *
 * The delivery may or may not have been created. Any retry must therefore
 * reuse the original Idempotency-Key so Dzpatch returns the first delivery
 * instead of creating a second one for the same order.
 */
class DzpatchUnavailableException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
