<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly PushNotificationService $pushNotificationService,
        private readonly ReferralRewardService $referralRewardService,
    )
    {
    }

    public function createCustomerOrder(User $user, array $data): Order
    {
        return $this->createOrderFromCart($user, $data);
    }

    public function quoteCart(string $restaurantId, array $items): array
    {
        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['At least one item is required.']]);
        }

        $restaurant = Restaurant::query()->find($restaurantId);

        if (! $restaurant || ! $restaurant->is_active) {
            throw ValidationException::withMessages(['restaurant_id' => ['Restaurant not found or inactive.']]);
        }

        $itemIds = collect($items)
            ->pluck('menu_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $menuItems = MenuItem::query()
            ->where('restaurant_id', $restaurantId)
            ->whereIn('id', $itemIds)
            ->where('is_available', true)
            ->get()
            ->keyBy('id');

        $validatedItems = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $menuItemId = Arr::get($item, 'menu_item_id');
            $quantity = (int) Arr::get($item, 'quantity', 0);

            if (! $menuItemId || $quantity < 1) {
                throw ValidationException::withMessages(['items' => ['Invalid cart item payload.']]);
            }

            $menuItem = $menuItems->get($menuItemId);

            if (! $menuItem) {
                throw ValidationException::withMessages(['items' => ['One or more items are unavailable.']]);
            }

            $unitPrice = (float) $menuItem->price;
            $lineTotal = round($unitPrice * $quantity, 2);
            $subtotal += $lineTotal;

            $validatedItems[] = [
                'menu_item_id' => $menuItem->id,
                'name' => $menuItem->name,
                'description' => $menuItem->description,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'total_price' => $lineTotal,
                'metadata' => $menuItem->metadata ?? [],
            ];
        }

        return [
            'restaurant' => $restaurant,
            'items' => $validatedItems,
            'subtotal' => round($subtotal, 2),
            'delivery_fee' => (float) config('foodhunts.default_delivery_fee', 0),
            'service_fee' => round($subtotal * (float) config('foodhunts.service_fee_rate', 0.05), 2),
        ];
    }

    public function resolveAddressForUser(User $user, string $addressId): Address
    {
        $address = Address::query()
            ->whereKey($addressId)
            ->where('user_id', $user->id)
            ->first();

        if (! $address) {
            throw ValidationException::withMessages(['delivery_address_id' => ['Delivery address not found.']]);
        }

        return $address;
    }

    public function createOrderFromCart(User $user, array $data): Order
    {
        $quote = $this->quoteCart($data['restaurant_id'], $data['items']);
        $subtotal = (float) $quote['subtotal'];
        $deliveryFee = (float) $quote['delivery_fee'];
        $serviceCharge = (float) $quote['service_fee'];
        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $totalAmount = round(max(0, $subtotal + $deliveryFee + $serviceCharge + $taxAmount - $discountAmount), 2);

        return DB::transaction(function () use ($user, $data, $quote, $subtotal, $deliveryFee, $serviceCharge, $taxAmount, $discountAmount, $totalAmount): Order {

            $order = Order::create([
                'user_id' => $user->id,
                'restaurant_id' => $data['restaurant_id'],
                'delivery_address_id' => $data['delivery_address_id'] ?? null,
                'status' => $data['status'] ?? OrderStatus::Pending->value,
                'payment_status' => $data['payment_status'] ?? PaymentStatus::Pending->value,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'service_charge' => $serviceCharge,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?? 'NGN',
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_provider' => $data['payment_provider'] ?? null,
                'payment_attempt_id' => $data['payment_attempt_id'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'order_snapshot' => [
                    'items' => $quote['items'],
                    'delivery_address_id' => $data['delivery_address_id'] ?? null,
                    'subtotal' => $subtotal,
                    'delivery_fee' => $deliveryFee,
                    'service_charge' => $serviceCharge,
                    'tax_amount' => $taxAmount,
                    'discount_amount' => $discountAmount,
                    'total_amount' => $totalAmount,
                ],
            ]);

            foreach ($quote['items'] as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'menu_item_id' => $item['menu_item_id'] ?? null,
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'total_price' => $item['total_price'],
                    'metadata' => $item['metadata'] ?? [],
                ]);
            }

            $order->load(['items', 'user', 'restaurant', 'address']);

            $this->pushNotificationService->notifyOrderPlaced($order);

            return $order;
        });
    }

    public function transition(Order $order, OrderStatus $status): Order
    {
        $order->update(['status' => $status->value]);

        if ($status === OrderStatus::Cancelled) {
            $this->referralRewardService->reverseRewardForOrder($order);
        }

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
