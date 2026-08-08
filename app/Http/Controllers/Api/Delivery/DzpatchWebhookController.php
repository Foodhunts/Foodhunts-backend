<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Services\Delivery\DeliveryEventProcessor;
use App\Services\Delivery\DzpatchWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives delivery events from Dzpatch.
 *
 * Unauthenticated by necessity — Dzpatch calls it directly — so the HMAC
 * signature is the only thing standing between this endpoint and anyone able
 * to mark an arbitrary order delivered.
 *
 * Status codes are chosen for their effect on Dzpatch's retry logic:
 *   200  handled, or knowingly ignored. Stop retrying.
 *   401  signature failed. Retrying will not help, and is not wanted.
 *   500  we broke. Please retry.
 */
class DzpatchWebhookController extends Controller
{
    public function __construct(
        private readonly DzpatchWebhookVerifier $verifier,
        private readonly DeliveryEventProcessor $processor,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            Log::warning('Rejected a Dzpatch webhook with an invalid signature', [
                'ip' => $request->ip(),
                'event_id' => $request->header('X-Dzpatch-Event-Id'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid signature.',
            ], 401);
        }

        $eventId = $request->header('X-Dzpatch-Event-Id');

        if (! is_string($eventId) || $eventId === '') {
            // Without an event id there is no idempotency key, so the event
            // cannot be safely stored or de-duplicated.
            return response()->json([
                'success' => false,
                'message' => 'Missing X-Dzpatch-Event-Id.',
            ], 400);
        }

        $payload = $request->json()->all();

        if (! is_array($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'Payload must be a JSON object.',
            ], 400);
        }

        try {
            $event = $this->processor->process($eventId, $payload);
        } catch (Throwable $e) {
            // Signature was valid and the event is probably fine; something on
            // our side failed. 500 asks Dzpatch to retry rather than losing it.
            Log::error('Failed to process a Dzpatch webhook', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not process the event.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Event received.',
            'data' => [
                'event_id' => $event->dzpatch_event_id,
                'processing_status' => $event->processing_status,
            ],
        ]);
    }
}
