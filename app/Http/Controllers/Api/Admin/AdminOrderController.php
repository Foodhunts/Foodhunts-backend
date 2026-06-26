<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;

class AdminOrderController extends Controller
{
    public function index()
    {
        return OrderResource::collection(Order::with(['items', 'user', 'restaurant', 'address'])->latest()->paginate(20));
    }

    public function show(Order $order)
    {
        return new OrderResource($order->load(['items', 'user', 'restaurant', 'address']));
    }
}
