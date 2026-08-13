<?php

namespace App\Http\Controllers\Api\Rider;

use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderDeliveryResource;
use App\Models\OrderDelivery;
use App\Services\DeliveryService;
use Illuminate\Http\Request;

/**
 * Rider-facing trip execution endpoints (plan §6.1).
 * Every mutation goes through DeliveryService::transition — no raw status writes.
 */
class DeliveryController extends Controller
{
    private const ACTION_TO_STATUS = [
        'en-route-pickup' => DeliveryStatus::RiderEnRoutePickup,
        'arrive-pickup' => DeliveryStatus::ArrivedPickup,
        'pick-up' => DeliveryStatus::PickedUp,
        'en-route-dropoff' => DeliveryStatus::EnRouteDropoff,
        'arrive-dropoff' => DeliveryStatus::ArrivedDropoff,
        'complete' => DeliveryStatus::Delivered,
        'fail' => DeliveryStatus::Failed,
    ];

    public function __construct(private readonly DeliveryService $deliveryService)
    {
    }

    public function show(Request $request, OrderDelivery $delivery): OrderDeliveryResource
    {
        $this->assertRiderOwns($request, $delivery);

        return new OrderDeliveryResource($delivery->load(['order.items', 'order.restaurant', 'order.address', 'rider.user']));
    }

    public function transition(Request $request, OrderDelivery $delivery, string $action): OrderDeliveryResource
    {
        $this->assertRiderOwns($request, $delivery);

        $status = self::ACTION_TO_STATUS[$action] ?? abort(404, 'Unknown delivery action.');

        if ($status === DeliveryStatus::Delivered) {
            // Verify the 4-digit delivery code before completing (plan §5.2).
            $code = (string) $request->input('delivery_code', '');
            if ($code === '' || ! hash_equals((string) $delivery->delivery_code, $code)) {
                abort(422, 'Invalid delivery code.');
            }
        }

        return new OrderDeliveryResource(
            $this->deliveryService->transition($delivery, $status, $request->user())->load(['order', 'rider.user'])
        );
    }

    private function assertRiderOwns(Request $request, OrderDelivery $delivery): void
    {
        $rider = \App\Models\Rider::query()->where('user_id', $request->user()->id)->first();

        abort_unless($rider, 403, 'Not a rider account.');
        abort_unless($delivery->rider_id === $rider->id, 403, 'This delivery is not assigned to you.');
    }
}
