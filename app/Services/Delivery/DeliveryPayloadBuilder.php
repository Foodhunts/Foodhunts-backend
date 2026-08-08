<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Services\Delivery\Exceptions\DeliveryNotDispatchableException;

/**
 * Turns a FoodHunts order into a Dzpatch partner delivery request.
 *
 * Dzpatch validates strictly and refuses the whole request on the first bad
 * field, so everything it requires is checked here first. A dispatch that
 * cannot be built is reported as a named local failure rather than sent and
 * rejected: the message then says which field is missing, instead of surfacing
 * a remote validation error nobody can act on.
 *
 * Phone numbers must be E.164. Nigerian numbers are stored in several shapes
 * across FoodHunts, so they are normalised rather than assumed correct.
 */
class DeliveryPayloadBuilder
{
    private const MAX_ADDRESS_LENGTH = 500;

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['items', 'user', 'restaurant', 'address']);

        $pickup = $this->buildPickup($order);
        $dropoff = $this->buildDropoff($order);

        return [
            'external_order_id' => (string) $order->id,
            'external_reference' => $order->payment_reference,
            'pickup' => $pickup,
            'dropoff' => $dropoff,
            'items' => $this->buildItems($order),
            'items_summary' => $this->buildItemsSummary($order),
            'customer' => [
                'name' => $this->customerName($order),
                'phone' => $this->normalizePhone($order->user?->phone),
            ],
            'pricing' => [
                'currency' => $order->currency ?: 'NGN',
                'partner_calculated_fee' => (float) $order->delivery_fee,
            ],
            'meta' => [
                'source' => 'foodhunts',
                'order_id' => (string) $order->id,
                'restaurant_id' => (string) $order->restaurant_id,
                'order_total' => (float) $order->total_amount,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPickup(Order $order): array
    {
        $restaurant = $order->restaurant;

        if ($restaurant === null) {
            throw new DeliveryNotDispatchableException('The order has no restaurant.');
        }

        // Not every restaurant has been through location onboarding, so this is
        // a real and common case rather than a defensive check. Refusing here
        // keeps the reason legible; sending it would fail Dzpatch validation
        // with a message about coordinates that says nothing about which
        // restaurant is unconfigured.
        $lat = $restaurant->latitude;
        $lng = $restaurant->longitude;

        if ($lat === null || $lng === null) {
            throw new DeliveryNotDispatchableException(
                "Restaurant {$restaurant->id} has no pickup coordinates. "
                .'Set its location before dispatching deliveries.'
            );
        }

        $address = $this->restaurantAddress($restaurant);

        if ($address === '') {
            throw new DeliveryNotDispatchableException(
                "Restaurant {$restaurant->id} has no pickup address."
            );
        }

        return [
            'name' => (string) $restaurant->name,
            'phone' => $this->normalizePhone($restaurant->phone_number),
            'address' => $this->truncateAddress($address),
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'instructions' => config('dzpatch.default_pickup_instructions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDropoff(Order $order): array
    {
        $address = $order->address;

        if ($address === null) {
            throw new DeliveryNotDispatchableException(
                'The order has no delivery address.'
            );
        }

        if ($address->latitude === null || $address->longitude === null) {
            throw new DeliveryNotDispatchableException(
                'The delivery address has no coordinates.'
            );
        }

        // Dzpatch requires a dropoff phone: it is how the rider reaches the
        // customer at the door. An order without one cannot be delivered, so
        // this is refused rather than sent with a null.
        $phone = $this->normalizePhone($order->user?->phone);

        if ($phone === null) {
            throw new DeliveryNotDispatchableException(
                'The customer has no usable phone number for the rider to call.'
            );
        }

        $line = trim(implode(', ', array_filter([
            $address->street_address,
            $address->city,
            $address->state,
        ])));

        if ($line === '') {
            throw new DeliveryNotDispatchableException(
                'The delivery address is empty.'
            );
        }

        return [
            'name' => $this->customerName($order),
            'phone' => $phone,
            'address' => $this->truncateAddress($line),
            'lat' => (float) $address->latitude,
            'lng' => (float) $address->longitude,
            'instructions' => $address->label ?: null,
        ];
    }

    /**
     * @return list<array{name: string, quantity: int}>
     */
    private function buildItems(Order $order): array
    {
        $items = [];

        foreach ($order->items as $item) {
            $items[] = [
                'name' => (string) $item->name,
                'quantity' => (int) $item->quantity,
            ];
        }

        if ($items === []) {
            throw new DeliveryNotDispatchableException('The order has no items.');
        }

        // Dzpatch caps the list at 100. A larger order is summarised rather
        // than refused: the rider needs the count, not every line.
        return array_slice($items, 0, 100);
    }

    private function buildItemsSummary(Order $order): ?string
    {
        $count = $order->items->sum('quantity');

        if ($count <= 0) {
            return null;
        }

        $restaurant = $order->restaurant?->name;

        return $restaurant
            ? "{$count} item(s) from {$restaurant}"
            : "{$count} item(s)";
    }

    private function customerName(Order $order): string
    {
        $user = $order->user;

        $name = trim((string) ($user?->name ?? ''));

        if ($name !== '') {
            return $name;
        }

        $composed = trim(implode(' ', array_filter([
            $user?->first_name,
            $user?->last_name,
        ])));

        return $composed !== '' ? $composed : 'FoodHunts customer';
    }

    private function restaurantAddress(object $restaurant): string
    {
        // house_number and street exist on the live schema but not in every
        // model definition, so they are read defensively.
        $parts = array_filter([
            $restaurant->house_number ?? null,
            $restaurant->street ?? null,
            $restaurant->city ?? null,
            $restaurant->state ?? null,
        ]);

        return trim(implode(', ', $parts));
    }

    private function truncateAddress(string $address): string
    {
        return mb_strlen($address) > self::MAX_ADDRESS_LENGTH
            ? mb_substr($address, 0, self::MAX_ADDRESS_LENGTH)
            : $address;
    }

    /**
     * Normalise to E.164, which is the only form Dzpatch accepts.
     *
     * Returns null rather than a best guess when the number cannot be resolved
     * confidently: a wrong number reaches a stranger, which is worse than no
     * number at all.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '+')) {
            $digits = preg_replace('/\D/', '', $trimmed) ?? '';

            return preg_match('/^[1-9]\d{7,14}$/', $digits) === 1
                ? '+'.$digits
                : null;
        }

        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        // 0803... -> +234803...
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '+234'.substr($digits, 1);
        }

        // 234803... already carries the country code.
        if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
            return '+'.$digits;
        }

        // 803... is missing only the country code.
        if (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            return '+234'.$digits;
        }

        return null;
    }
}
