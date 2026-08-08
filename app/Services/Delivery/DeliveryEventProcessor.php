<?php

namespace App\Services\Delivery;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies inbound Dzpatch events to the local delivery and its order.
 *
 * Two properties matter here, and both are enforced in the database rather than
 * in PHP, because two webhook deliveries can be processed at the same instant:
 *
 *   - At most once. `dzpatch_event_id` is unique, so a retried webhook is a
 *     constraint violation rather than a second state change.
 *   - In order. Events can overtake each other in transit, so an event whose
 *     sequence is not greater than the last applied one is recorded and
 *     ignored. Without this a delayed `picked_up` could move a delivered order
 *     backwards.
 */
class DeliveryEventProcessor
{
    /**
     * Record and apply an event.
     *
     * Returns the stored event in every case, including duplicates and stale
     * arrivals, so the caller can answer 2xx and stop Dzpatch retrying: both
     * are correct outcomes, not failures.
     *
     * @param  array<string, mixed>  $payload
     */
    public function process(string $eventId, array $payload): DeliveryEvent
    {
        $body = is_array($payload['delivery'] ?? null) ? $payload['delivery'] : [];

        $externalOrderId = is_string($body['external_order_id'] ?? null)
            ? $body['external_order_id']
            : null;

        $delivery = $this->resolveDelivery($body, $externalOrderId);

        $event = $this->record($eventId, $payload, $body, $externalOrderId, $delivery);

        // A duplicate has already been applied; re-applying it is exactly what
        // the inbox exists to prevent.
        if ($event->processing_status !== 'received') {
            return $event;
        }

        if ($delivery === null) {
            // An event for a delivery we have no record of. Kept rather than
            // discarded so the orphan is visible and can be reconciled.
            $event->forceFill([
                'processing_status' => 'failed',
                'processing_error' => 'No local delivery matches this event.',
                'processed_at' => now(),
            ])->save();

            return $event;
        }

        return $this->apply($event, $delivery, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resolveDelivery(array $body, ?string $externalOrderId): ?Delivery
    {
        $deliveryId = is_string($body['delivery_id'] ?? null) ? $body['delivery_id'] : null;

        if ($deliveryId !== null) {
            $delivery = Delivery::query()
                ->where('dzpatch_delivery_id', $deliveryId)
                ->first();

            if ($delivery !== null) {
                return $delivery;
            }
        }

        if ($externalOrderId === null) {
            return null;
        }

        // Falling back to external_order_id covers the window where Dzpatch
        // accepted the delivery but our response never arrived, so the local row
        // has no dzpatch_delivery_id yet. Adopting it here means the first
        // webhook repairs the gap instead of orphaning the delivery.
        $delivery = Delivery::query()
            ->where('external_order_id', $externalOrderId)
            ->first();

        if ($delivery !== null && $delivery->dzpatch_delivery_id === null && $deliveryId !== null) {
            $delivery->forceFill(['dzpatch_delivery_id' => $deliveryId])->save();
        }

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $body
     */
    private function record(
        string $eventId,
        array $payload,
        array $body,
        ?string $externalOrderId,
        ?Delivery $delivery,
    ): DeliveryEvent {
        try {
            return DeliveryEvent::create([
                'delivery_id' => $delivery?->id,
                'dzpatch_event_id' => $eventId,
                'event_type' => is_string($payload['event_type'] ?? null)
                    ? $payload['event_type']
                    : 'unknown',
                'status' => is_string($body['status'] ?? null) ? $body['status'] : null,
                'sequence' => (int) ($payload['sequence'] ?? 0),
                'external_order_id' => $externalOrderId,
                'payload' => $payload,
                'processing_status' => 'received',
                'occurred_at' => $this->parseTimestamp($payload['occurred_at'] ?? null),
            ]);
        } catch (QueryException $e) {
            // Unique violation on dzpatch_event_id: Dzpatch retried an event we
            // already hold. Returning the stored row lets the caller answer 2xx
            // so the retries stop.
            $existing = DeliveryEvent::query()
                ->where('dzpatch_event_id', $eventId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function apply(DeliveryEvent $event, Delivery $delivery, array $body): DeliveryEvent
    {
        return DB::transaction(function () use ($event, $delivery, $body): DeliveryEvent {
            $delivery = Delivery::query()
                ->whereKey($delivery->id)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                $event->forceFill([
                    'processing_status' => 'failed',
                    'processing_error' => 'The delivery disappeared while processing.',
                    'processed_at' => now(),
                ])->save();

                return $event;
            }

            // Sequence 0 means Dzpatch did not number this event, so ordering
            // cannot be established and the event is applied as it arrives.
            if ($event->sequence > 0 && $event->sequence <= $delivery->last_event_sequence) {
                $event->forceFill([
                    'processing_status' => 'ignored',
                    'processing_error' => 'Superseded: sequence '.$event->sequence
                        .' is not newer than '.$delivery->last_event_sequence.'.',
                    'processed_at' => now(),
                ])->save();

                return $event;
            }

            $status = is_string($body['status'] ?? null) ? $body['status'] : null;

            $attributes = array_filter([
                'status' => $status,
                'delivery_code' => $body['delivery_code'] ?? null,
                'rider_name' => $this->riderField($body, 'name'),
                'rider_phone' => $this->riderField($body, 'phone'),
                'last_error' => null,
            ], static fn ($value): bool => $value !== null);

            $lat = $this->riderField($body, 'lat');
            $lng = $this->riderField($body, 'lng');

            if ($lat !== null && $lng !== null) {
                $attributes['rider_lat'] = $lat;
                $attributes['rider_lng'] = $lng;
                $attributes['rider_location_updated_at'] = now();
            }

            if ($event->sequence > 0) {
                $attributes['last_event_sequence'] = $event->sequence;
            }

            $attributes += $this->statusTimestamps($status);

            $delivery->forceFill($attributes)->save();

            $this->syncOrder($delivery, $status);

            $event->forceFill([
                'delivery_id' => $delivery->id,
                'processing_status' => 'processed',
                'processed_at' => now(),
            ])->save();

            return $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function statusTimestamps(?string $status): array
    {
        return match ($status) {
            DeliveryStatus::Accepted->value => ['accepted_at' => now()],
            DeliveryStatus::PickedUp->value => ['picked_up_at' => now()],
            DeliveryStatus::Delivered->value => ['delivered_at' => now()],
            DeliveryStatus::Cancelled->value => ['cancelled_at' => now()],
            default => [],
        };
    }

    /**
     * Move the order to match the delivery.
     *
     * Only two transitions are made, and both are forward-only. A terminal
     * order is never reopened by a delivery event: refunds and cancellations
     * are decided by FoodHunts, and letting a late webhook overwrite that would
     * put an order back into a state its money has already left.
     */
    private function syncOrder(Delivery $delivery, ?string $status): void
    {
        $order = $delivery->order;

        if ($order === null) {
            return;
        }

        if (in_array($order->status, [
            OrderStatus::Completed->value,
            OrderStatus::Cancelled->value,
            OrderStatus::Rejected->value,
        ], true)) {
            return;
        }

        $target = match ($status) {
            DeliveryStatus::PickedUp->value,
            DeliveryStatus::InTransit->value,
            DeliveryStatus::ArrivedDropoff->value => OrderStatus::OutForDelivery->value,
            DeliveryStatus::Delivered->value => OrderStatus::Delivered->value,
            default => null,
        };

        if ($target === null || $order->status === $target) {
            return;
        }

        // Delivered is final for the order, so it is never stepped back to
        // out_for_delivery by an event that arrives late.
        if ($order->status === OrderStatus::Delivered->value
            && $target === OrderStatus::OutForDelivery->value) {
            return;
        }

        $order->forceFill(['status' => $target])->save();

        Log::info('Order status updated from a Dzpatch delivery event', [
            'order_id' => $order->id,
            'delivery_id' => $delivery->id,
            'status' => $target,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function riderField(array $body, string $key): mixed
    {
        $rider = is_array($body['rider'] ?? null) ? $body['rider'] : [];

        return $rider[$key] ?? null;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
