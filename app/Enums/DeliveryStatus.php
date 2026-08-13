<?php

namespace App\Enums;

/**
 * Rider-facing trip sub-state machine (see docs/delivery-feature-implementation.md §5).
 *
 * Lives on order_deliveries.status. Maps onto the customer-facing
 * orders.status chain at two points:
 *   - entering RiderAssigned        -> orders.status = out_for_delivery
 *   - reaching Delivered            -> orders.status = delivered
 */
enum DeliveryStatus: string
{
    case PendingDispatch = 'pending_dispatch';
    case RiderAssigned = 'rider_assigned';
    case RiderEnRoutePickup = 'rider_en_route_pickup';
    case ArrivedPickup = 'arrived_pickup';
    case PickedUp = 'picked_up';
    case EnRouteDropoff = 'en_route_dropoff';
    case ArrivedDropoff = 'arrived_dropoff';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Statuses that count as an active trip for the one-active-trip-per-rider
     * DB constraint and dispatch eligibility.
     */
    public static function activeTripStatuses(): array
    {
        return [
            self::RiderAssigned->value,
            self::RiderEnRoutePickup->value,
            self::ArrivedPickup->value,
            self::PickedUp->value,
            self::EnRouteDropoff->value,
            self::ArrivedDropoff->value,
        ];
    }

    public function isActiveTrip(): bool
    {
        return in_array($this, [
            self::RiderAssigned,
            self::RiderEnRoutePickup,
            self::ArrivedPickup,
            self::PickedUp,
            self::EnRouteDropoff,
            self::ArrivedDropoff,
        ], true);
    }
}
