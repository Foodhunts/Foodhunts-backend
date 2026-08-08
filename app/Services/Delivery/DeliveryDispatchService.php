<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Services\Delivery\Exceptions\DeliveryNotDispatchableException;
use App\Services\Delivery\Exceptions\DzpatchRejectedException;
use App\Services\Delivery\Exceptions\DzpatchUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dispatches FoodHunts orders to Dzpatch.
 *
 * The ordering here is deliberate: a local `pending` row is committed BEFORE
 * the HTTP call, so a delivery that Dzpatch accepts can never be invisible to
 * us. The opposite order — call first, record after — loses the delivery
 * entirely if the process dies mid-request, and nothing then reconciles it.
 *
 * That local row also carries the idempotency key, which is generated once and
 * reused for every retry of the same order. Dzpatch returns the original
 * delivery for a repeated key, so a retry after a timeout adopts the existing
 * delivery rather than creating a second rider for one order.
 */
class DeliveryDispatchService
{
    public function __construct(
        private readonly DzpatchClient $client,
        private readonly DeliveryPayloadBuilder $payloadBuilder,
    ) {
    }

    /**
     * Dispatch an order, or return the delivery it already has.
     *
     * Safe to call more than once for the same order. Returns null when
     * dispatch is disabled, so the caller can hook this into an order
     * transition without having to know whether the integration is on.
     */
    public function dispatch(Order $order): ?Delivery
    {
        if (! config('dzpatch.enabled')) {
            return null;
        }

        $existing = $this->existingDelivery($order);

        if ($existing !== null) {
            return $existing;
        }

        $delivery = $this->reserve($order);

        return $this->send($delivery, $order);
    }

    /**
     * Retry a dispatch that failed locally or was never acknowledged.
     *
     * Reuses the original idempotency key, so this cannot produce a second
     * delivery for an order Dzpatch already accepted.
     */
    public function retry(Delivery $delivery): Delivery
    {
        if ($delivery->dzpatch_delivery_id !== null) {
            return $delivery;
        }

        $order = $delivery->order;

        if ($order === null) {
            throw new DeliveryNotDispatchableException(
                'The delivery has no order to dispatch.'
            );
        }

        return $this->send($delivery, $order);
    }

    /**
     * An existing delivery for this order, if it is still live.
     *
     * A failed delivery is deliberately not returned: the order still needs a
     * rider, so it must be allowed to dispatch again.
     */
    private function existingDelivery(Order $order): ?Delivery
    {
        $delivery = Delivery::query()
            ->where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->first();

        if ($delivery === null) {
            return null;
        }

        if (DeliveryStatus::isFailure($delivery->status)) {
            return null;
        }

        return $delivery;
    }

    /**
     * Commit the local record before contacting Dzpatch.
     *
     * external_order_id is unique, so two concurrent dispatches of one order
     * cannot both reserve: the second hits a constraint violation and is
     * resolved by reading the row the first one wrote.
     */
    private function reserve(Order $order): Delivery
    {
        return DB::transaction(function () use ($order): Delivery {
            $existing = Delivery::query()
                ->where('external_order_id', (string) $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! DeliveryStatus::isFailure($existing->status)) {
                return $existing;
            }

            return Delivery::create([
                'order_id' => $order->id,
                // The order id doubles as the external id on the first attempt.
                // A retry after a hard failure needs a distinct value, because
                // Dzpatch treats a repeat as the same delivery.
                'external_order_id' => $existing === null
                    ? (string) $order->id
                    : (string) $order->id.'-'.Str::lower(Str::random(6)),
                'status' => DeliveryStatus::Pending->value,
                'idempotency_key' => (string) Str::uuid(),
                'currency' => $order->currency ?: 'NGN',
                'submitted_fee' => $order->delivery_fee,
            ]);
        });
    }

    private function send(Delivery $delivery, Order $order): Delivery
    {
        try {
            $payload = $this->payloadBuilder->build($order);
        } catch (DeliveryNotDispatchableException $e) {
            // Nothing was sent, so this is terminal until the underlying data
            // is corrected. Recording it makes the reason visible on the order
            // rather than only in a log line.
            $this->markFailed($delivery, $e->getMessage());

            throw $e;
        }

        $delivery->forceFill([
            'request_payload' => $payload,
            'dispatch_attempts' => $delivery->dispatch_attempts + 1,
            'dispatched_at' => now(),
        ])->save();

        try {
            $response = $this->client->createDelivery($payload, $delivery->idempotency_key);
        } catch (DzpatchRejectedException $e) {
            if ($e->isDuplicate()) {
                // Dzpatch already has this order. An earlier attempt got
                // further than our record shows, so the existing delivery is
                // adopted instead of being treated as a failure.
                return $this->adoptExisting($delivery, $order);
            }

            $this->markFailed($delivery, $e->getMessage());

            throw $e;
        } catch (DzpatchUnavailableException $e) {
            // No verdict. The delivery is left pending rather than failed,
            // because Dzpatch may well have accepted it; retry() will reuse the
            // same idempotency key and find out.
            $delivery->forceFill([
                'last_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        return $this->applyResponse($delivery, $response);
    }

    /**
     * Adopt the delivery Dzpatch already holds for this order.
     */
    private function adoptExisting(Delivery $delivery, Order $order): Delivery
    {
        try {
            $response = $this->client->getDeliveryByExternalOrderId(
                $delivery->external_order_id
            );

            return $this->applyResponse($delivery, $response);
        } catch (DzpatchRejectedException|DzpatchUnavailableException $e) {
            // The duplicate is real but unreadable right now. Left pending so a
            // later retry can reconcile it, rather than failed, which would
            // invite a second dispatch for an order that already has a rider.
            Log::warning('Could not read the existing Dzpatch delivery', [
                'delivery_id' => $delivery->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $delivery->forceFill(['last_error' => $e->getMessage()])->save();

            return $delivery->refresh();
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function applyResponse(Delivery $delivery, array $response): Delivery
    {
        $pricing = is_array($response['pricing'] ?? null) ? $response['pricing'] : [];
        $code = is_array($response['delivery_code'] ?? null) ? $response['delivery_code'] : [];
        $tracking = is_array($response['tracking'] ?? null) ? $response['tracking'] : [];
        $timestamps = is_array($response['timestamps'] ?? null) ? $response['timestamps'] : [];

        $delivery->forceFill(array_filter([
            'dzpatch_delivery_id' => $response['delivery_id'] ?? null,
            'dzpatch_order_id' => $response['dzpatch_order_id'] ?? null,
            'status' => $response['status'] ?? DeliveryStatus::Accepted->value,
            'delivery_code' => $code['code'] ?? null,
            'delivery_code_status' => $code['status'] ?? null,
            'submitted_fee' => $pricing['submitted_fee'] ?? null,
            'applied_fee' => $pricing['applied_fee'] ?? null,
            'pricing_source' => $pricing['pricing_source'] ?? null,
            'tracking_url' => $tracking['tracking_url'] ?? null,
            'accepted_at' => $timestamps['accepted_at'] ?? null,
            'response_payload' => $response,
            'last_error' => null,
        ], static fn ($value): bool => $value !== null))->save();

        return $delivery->refresh();
    }

    private function markFailed(Delivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => DeliveryStatus::DispatchFailed->value,
            'last_error' => $reason,
        ])->save();
    }
}
