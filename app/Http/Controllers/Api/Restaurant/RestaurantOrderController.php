<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\AssignRiderRequest;
use App\Http\Resources\OrderDeliveryResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Rider;
use App\Services\DeliveryService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantOrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly DeliveryService $deliveryService,
    ) {
    }

    public function index(Request $request)
    {
        $restaurantId = $this->orderService->resolveRestaurantIdForOwner($request->user());

        return OrderResource::collection(
            Order::query()->where('restaurant_id', $restaurantId)->with(['items', 'user', 'address'])->latest()->get()
        );
    }

    public function show(Request $request, Order $order): OrderResource
    {
        $this->orderService->assertRestaurantOwnership($request->user(), $order);

        return new OrderResource($order->load(['items', 'user', 'address']));
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $this->orderService->assertRestaurantOwnership($request->user(), $order);

        return response()->json(new OrderResource($this->orderService->transition($order, OrderStatus::Accepted)));
    }

    public function markPreparing(Request $request, Order $order): JsonResponse
    {
        $this->orderService->assertRestaurantOwnership($request->user(), $order);

        return response()->json(new OrderResource($this->orderService->transition($order, OrderStatus::Preparing)));
    }

    public function markReady(Request $request, Order $order): JsonResponse
    {
        $this->orderService->assertRestaurantOwnership($request->user(), $order);

        return response()->json(new OrderResource($this->orderService->transition($order, OrderStatus::Ready)));
    }

    public function assignRider(AssignRiderRequest $request, Order $order): OrderDeliveryResource
    {
        $this->orderService->assertRestaurantOwnership($request->user(), $order);

        $rider = Rider::query()->findOrFail($request->validated('rider_id'));

        return new OrderDeliveryResource(
            $this->deliveryService->assignRider($order, $rider)->load(['order', 'rider.user'])
        );
    }
}
