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
            // v1 returns idempotency_conflict; delivery_already_exists is the
            // legacy surface's name for the same condition.
            'idempotency_conflict',
            'delivery_already_exists',
        ], true);
    }

    /**
     * Whether the refusal is about the partner account rather than this order.
     *
     * v1 returns `partner_pricing_rejected` when the fee is below the agreed
     * floor or outside the service area, and `service_unavailable` when the
     * partner account is not configured to dispatch (no billing profile, or an
     * empty partner wallet). Neither is fixed by editing the order, so these
     * need an operator rather than a retry — and saying so beats a generic
     * "Dzpatch rejected the request" in the log.
     */
    public function isAccountProblem(): bool
    {
        return in_array($this->errorCode, [
            'partner_pricing_rejected',
            'service_unavailable',
        ], true);
    }
}
