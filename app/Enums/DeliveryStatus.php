<?php

namespace App\Enums;

/**
 * Delivery states.
 *
 * Every case from Accepted downwards is Dzpatch's own PartnerDeliveryStatus,
 * spelled identically on purpose. Pending and DispatchFailed are local: they
 * describe a delivery Dzpatch has not acknowledged yet, or has refused.
 *
 * Statuses arriving on a webhook are NOT required to appear here. An unknown
 * value is stored as it came so it can be seen and explained, rather than
 * rejected and lost. This enum exists to reason about the states we know.
 */
enum DeliveryStatus: string
{
    // Local
    case Pending = 'pending';
    case DispatchFailed = 'dispatch_failed';

    // Dzpatch
    case Accepted = 'accepted';
    case RiderAssigned = 'rider_assigned';
    case ArrivedPickup = 'arrived_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case ArrivedDropoff = 'arrived_dropoff';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case FailedNoRider = 'failed_no_rider';

    /**
     * A delivery in a terminal state will receive no further events.
     *
     * Takes a raw string because it is called with statuses that came off the
     * wire and may not be a case of this enum.
     */
    public static function isTerminal(?string $status): bool
    {
        return in_array($status, [
            self::Delivered->value,
            self::Cancelled->value,
            self::Failed->value,
            self::FailedNoRider->value,
            self::DispatchFailed->value,
        ], true);
    }

    /**
     * Whether a status means the delivery never started.
     *
     * Distinct from terminal: these are the states where the order still needs
     * an alternative — another dispatch, or a manual courier.
     */
    public static function isFailure(?string $status): bool
    {
        return in_array($status, [
            self::Failed->value,
            self::FailedNoRider->value,
            self::DispatchFailed->value,
        ], true);
    }
}
