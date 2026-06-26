<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private readonly PushNotificationService $pushNotificationService)
    {
    }

    public function createCustomerOrder(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data): Order {
            $order = Order::create([
                'user_id' => $user->id,
                'restaurant_id' => $data['restaurant_id'],
                'delivery_address_id' => $data['delivery_address_id'] ?? null,
                'status' => OrderStatus::Pending->value,
                'payment_status' => PaymentStatus::Pending->value,
                'subtotal' => $data['subtotal'],
                'delivery_fee' => $data['delivery_fee'] ?? 0,
                'tax_amount' => $data['tax_amount'] ?? 0,
                'discount_amount' => $data['discount_amount'] ?? 0,
                'total_amount' => $data['total_amount'],
                'currency' => $data['currency'] ?? 'NGN',
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_provider' => $data['payment_provider'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'order_snapshot' => [
                    'items' => $data['items'],
                    'delivery_address_id' => $data['delivery_address_id'] ?? null,
                ],
            ]);

            foreach ($data['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $item['menu_item_id'] ?? null,
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'total_price' => (float) $item['unit_price'] * (int) $item['quantity'],
                    'metadata' => $item['metadata'] ?? [],
                ]);
            }

            $order->load('items');

            $this->pushNotificationService->notifyOrderPlaced($order);

            return $order;
        });
    }

    public function transition(Order $order, OrderStatus $status): Order
    {
        $order->update(['status' => $status->value]);

        $this->pushNotificationService->notifyOrderStatusChanged($order);

        return $order->refresh();
    }

    public function resolveRestaurantIdForOwner(User $user): string
    {
        $restaurantId = $user->restaurant?->id;

        if (! $restaurantId) {
            throw ValidationException::withMessages(['restaurant' => ['Restaurant profile not found.']]);
        }

        return $restaurantId;
    }

    public function assertRestaurantOwnership(User $user, Order $order): void
    {
        $restaurantId = $this->resolveRestaurantIdForOwner($user);

        if ($user->role !== 'admin' && $order->restaurant_id !== $restaurantId) {
            abort(403, 'Forbidden');
        }
    }
}
