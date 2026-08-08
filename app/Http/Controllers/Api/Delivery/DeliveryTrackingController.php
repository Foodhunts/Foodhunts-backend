<?php

namespace App\Http\Controllers\Api\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery tracking for the customer who placed the order.
 *
 * Polled by the app, so the response is deliberately small and exposes only
 * what a customer needs to watch their food arrive: the rider's name, a phone
 * number to call, and a position. Everything else on the delivery row —
 * idempotency keys, request payloads, fees, error text — stays server-side.
 */
class DeliveryTrackingController extends Controller
{
    public function __invoke(Request $request, Order $order): JsonResponse
    {
        // Ownership is checked before existence: answering "no delivery" for an
        // order that is not yours still confirms the order id is real.
        abort_unless($order->user_id === $request->user()->id, 403);

        $delivery = Delivery::query()
            ->where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->first();

        if ($delivery === null) {
            return response()->json([
                'success' => true,
                'message' => 'No delivery has been dispatched for this order.',
                'data' => [
                    'order_id' => (string) $order->id,
                    'order_status' => $order->status,
                    'delivery' => null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery tracking fetched successfully.',
            'data' => [
                'order_id' => (string) $order->id,
                'order_status' => $order->status,
                'delivery' => [
                    'status' => $delivery->status,
                    // Shown to the rider at handover, so the customer needs it.
                    'delivery_code' => $delivery->delivery_code,
                    'tracking_url' => $delivery->tracking_url,
                    'rider' => $delivery->rider_name === null ? null : [
                        'name' => $delivery->rider_name,
                        'phone' => $delivery->rider_phone,
                        'location' => $delivery->rider_lat === null ? null : [
                            'lat' => (float) $delivery->rider_lat,
                            'lng' => (float) $delivery->rider_lng,
                            'updated_at' => $delivery->rider_location_updated_at?->toIso8601String(),
                        ],
                    ],
                    'timestamps' => [
                        'dispatched_at' => $delivery->dispatched_at?->toIso8601String(),
                        'accepted_at' => $delivery->accepted_at?->toIso8601String(),
                        'picked_up_at' => $delivery->picked_up_at?->toIso8601String(),
                        'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                        'cancelled_at' => $delivery->cancelled_at?->toIso8601String(),
                    ],
                ],
            ],
        ]);
    }
}
