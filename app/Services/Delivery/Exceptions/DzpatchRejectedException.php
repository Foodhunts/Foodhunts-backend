<?php

namespace App\Services\Delivery\Exceptions;

use RuntimeException;

/**
 * Dzpatch understood the request and refused it.
 *
 * Retrying the same payload will produce the same refusal, so a delivery that
 * ends here needs either a corrected request or a human.
 */
class DzpatchRejectedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unknown_error',
        public readonly int $statusCode = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Whether the refusal means a delivery already exists for this order.
     *
     * Not really an error: it means an earlier attempt succeeded further than
     * we recorded, and the caller should adopt the existing delivery rather
     * than treat the dispatch as failed.
     */
    public function isDuplicate(): bool
    {
        return in_array($this->errorCode, [
            'delivery_already_exists',
            'idempotency_conflict',
        ], true);
    }
}
