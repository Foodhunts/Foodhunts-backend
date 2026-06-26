<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantOrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
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
}
