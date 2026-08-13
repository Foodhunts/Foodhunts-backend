<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trip state machine for the rider-facing delivery sub-chain.
 *
 * Legal transitions (docs/delivery-feature-implementation.md §5.2):
 *   pending_dispatch      -> rider_assigned | cancelled
 *   rider_assigned        -> rider_en_route_pickup | failed | cancelled
 *   rider_en_route_pickup -> arrived_pickup | failed
 *   arrived_pickup        -> picked_up | failed
 *   picked_up             -> en_route_dropoff | failed
 *   en_route_dropoff      -> arrived_dropoff | failed
 *   arrived_dropoff       -> delivered | failed
 *
 * orders.status sync:
 *   first active assignment -> out_for_delivery
 *   delivered               -> delivered (+ actual_delivery_time)
 */
class DeliveryService
{
    private const TRANSITIONS = [
        'pending_dispatch' => ['rider_assigned', 'cancelled'],
        'rider_assigned' => ['rider_en_route_pickup', 'failed', 'cancelled'],
        'rider_en_route_pickup' => ['arrived_pickup', 'failed'],
        'arrived_pickup' => ['picked_up', 'failed'],
        'picked_up' => ['en_route_dropoff', 'failed'],
        'en_route_dropoff' => ['arrived_dropoff', 'failed'],
        'arrived_dropoff' => ['delivered', 'failed'],
    ];

    public function __construct(
        private readonly PushNotificationService $pushNotificationService,
    ) {
    }

    public function assignRider(Order $order, Rider $rider, string $dispatchMode = 'manual'): OrderDelivery
    {
        return DB::transaction(function () use ($order, $rider, $dispatchMode): OrderDelivery {
            $this->assertRiderEligibleForAssignment($rider);

            $delivery = OrderDelivery::create([
                'order_id' => $order->id,
                'rider_id' => $rider->id,
                'status' => DeliveryStatus::RiderAssigned->value,
                'dispatch_mode' => $dispatchMode,
                'delivery_code' => $this->generateDeliveryCode(),
                'assigned_at' => now(),
            ]);

            $this->syncOrderStatus($order, OrderStatus::OutForDelivery);

            return $delivery;
        });
    }

    public function transition(
        OrderDelivery $delivery,
        DeliveryStatus $to,
        ?User $actor = null,
        array $metadata = [],
    ): OrderDelivery {
        return DB::transaction(function () use ($delivery, $to, $actor, $metadata): OrderDelivery {
            $from = $delivery->status->value;

            if (! in_array($to->value, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Illegal delivery transition: {$from} -> {$to->value}."],
                ]);
            }

            $updates = ['status' => $to->value];

            if ($to === DeliveryStatus::PickedUp) {
                $updates['picked_up_at'] = now();
            }
            if ($to === DeliveryStatus::Delivered) {
                $updates['delivered_at'] = now();
            }
            if ($metadata !== []) {
                $updates['metadata'] = array_merge($delivery->metadata ?? [], $metadata);
            }

            $delivery->update($updates);

            $order = $delivery->order;

            if ($to === DeliveryStatus::Delivered) {
                $order->update([
                    'status' => OrderStatus::Delivered->value,
                    'actual_delivery_time' => now(),
                ]);
            }

            // TODO (Phase 4): on Delivered, credit rider earnings
            // (wallet_transactions category=delivery_earnings, reference
            // DELIVERY_EARNINGS_{delivery_id}, idempotent via unique reference).

            $this->pushNotificationService->notifyOrderStatusChanged($order);

            return $delivery->refresh();
        });
    }

    public function hasActiveTrip(Rider $rider): bool
    {
        return OrderDelivery::query()
            ->where('rider_id', $rider->id)
            ->whereIn('status', DeliveryStatus::activeTripStatuses())
            ->exists();
    }

    public function generateDeliveryCode(): string
    {
        return (string) random_int(1000, 9999);
    }

    private function assertRiderEligibleForAssignment(Rider $rider): void
    {
        if ($rider->kyc_status !== \App\Enums\RiderKycStatus::Approved) {
            throw ValidationException::withMessages([
                'rider_id' => ['Rider is not KYC approved.'],
            ]);
        }

        if (! $rider->is_online) {
            throw ValidationException::withMessages([
                'rider_id' => ['Rider is offline.'],
            ]);
        }

        if ($this->hasActiveTrip($rider)) {
            throw ValidationException::withMessages([
                'rider_id' => ['Rider already has an active trip.'],
            ]);
        }
    }

    private function syncOrderStatus(Order $order, OrderStatus $status): void
    {
        $order->update(['status' => $status->value]);
    }
}
