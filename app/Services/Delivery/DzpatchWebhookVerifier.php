<?php

namespace App\Services\Delivery;

use Illuminate\Http\Request;

/**
 * Verifies the signature on an inbound Dzpatch webhook.
 *
 * Dzpatch signs "{timestamp}.{raw body}" with HMAC-SHA256 and sends the result
 * as `X-Dzpatch-Signature: v1=<hex>`. The signature covers the exact bytes
 * transmitted, so verification uses the raw request body: re-encoding a decoded
 * payload changes key order and whitespace, and the signature would never match.
 *
 * Without this check the webhook endpoint is an unauthenticated way to move any
 * order to `delivered`, since it must be publicly reachable for Dzpatch to call
 * it.
 */
class DzpatchWebhookVerifier
{
    public function verify(Request $request): bool
    {
        $secret = (string) config('dzpatch.webhook_secret');

        if ($secret === '') {
            // An unset secret must fail closed. Accepting unsigned webhooks
            // because none can be verified would defeat the purpose entirely.
            return false;
        }

        $signature = $request->header('X-Dzpatch-Signature');
        $timestamp = $request->header('X-Dzpatch-Timestamp');

        if (! is_string($signature) || ! is_string($timestamp) || $timestamp === '') {
            return false;
        }

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $provided = str_starts_with($signature, 'v1=')
            ? substr($signature, 3)
            : $signature;

        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            $secret,
        );

        // Constant-time: a plain === leaks how much of the signature matched,
        // which is enough to forge one byte at a time.
        return hash_equals($expected, $provided);
    }

    /**
     * Reject signatures older than the configured tolerance.
     *
     * A valid signature stays valid forever, so without this a captured request
     * could be replayed indefinitely. The inbox stops a replay from applying
     * twice; this stops it being accepted at all.
     */
    private function timestampIsFresh(string $timestamp): bool
    {
        if (! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = (int) config('dzpatch.webhook_tolerance_seconds', 300);
        $drift = abs(time() - (int) $timestamp);

        return $drift <= $tolerance;
    }
}
