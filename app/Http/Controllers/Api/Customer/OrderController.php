<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    public function index(Request $request)
    {
        return OrderResource::collection(
            $request->user()->orders()->with(['items', 'restaurant', 'address'])->latest()->get()
        );
    }

    public function store(StoreOrderRequest $request): OrderResource
    {
        return new OrderResource($this->orderService->createCustomerOrder($request->user(), $request->validated()));
    }

    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        return new OrderResource($order->load(['items', 'restaurant', 'address']));
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        return new OrderResource($this->orderService->transition($order, \App\Enums\OrderStatus::Cancelled));
    }

    public function tracking(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);
        return response()->json(['success' => true, 'message' => 'Order tracking fetched successfully', 'data' => ['order_id' => $order->id, 'status' => $order->status, 'history' => []]]);
    }
}
