<?php

namespace App\Services;

use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    /**
     * Order status → [title, body]. Mirrors the mobile `push` STATUS_MESSAGES.
     */
    private const ORDER_STATUS_COPY = [
        'accepted' => ['Order accepted', 'Your order has been accepted and is now being prepared.'],
        'confirmed' => ['Order accepted', 'Your order has been accepted and is now being prepared.'],
        'preparing' => ['Order preparing', 'Our team is preparing your order now.'],
        'ready' => ['Order ready', 'Your order is ready.'],
        'out_for_delivery' => ['Out for delivery', 'Your order is on the way to you.'],
        'delivered' => ['Order delivered', 'Your order has been delivered successfully.'],
        'rejected' => ['Order rejected', "We're sorry, your order could not be accepted."],
        'cancelled' => ['Order cancelled', 'Your order has been cancelled.'],
    ];

    public function registerToken(User $user, array $data): array
    {
        $token = PushToken::updateOrCreate(
            ['device_id' => $data['device_id']],
            [
                'user_id' => $user->id,
                'expo_push_token' => $data['expo_push_token'],
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]
        );

        return ['push_token' => $token];
    }

    public function notifyOrderPlaced(Order $order): void
    {
        $this->enqueue(
            $order->user_id,
            'order_placed',
            'Order placed',
            'Your order has been received and is being processed.',
            ['order_id' => $order->id],
            "order:{$order->id}:placed"
        );
    }

    public function notifyOrderStatusChanged(Order $order, ?string $title = null, ?string $body = null): void
    {
        $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;

        [$defaultTitle, $defaultBody] = self::ORDER_STATUS_COPY[$status] ?? ['Order updated', 'Your order status has changed.'];

        $this->enqueue(
            $order->user_id,
            'order_status_changed',
            $title ?? $defaultTitle,
            $body ?? $defaultBody,
            ['order_id' => $order->id, 'status' => $status],
            "order:{$order->id}:status:{$status}"
        );
    }

    /**
     * Write a push intent to the transactional outbox.
     *
     * The row is committed atomically with the caller's DB transaction — the
     * state mutation and the push intent live or die together, so a crash can
     * never lose a notification. A background processor (`push:send-pending`)
     * drains the outbox and talks to Expo; nothing here blocks the request or
     * makes a synchronous HTTP call to a third party.
     */
    public function enqueue(
        ?string $userId,
        string $event,
        string $title,
        string $body,
        array $data = [],
        ?string $dedupKey = null,
    ): ?NotificationOutbox {
        try {
            return NotificationOutbox::create([
                'user_id' => $userId,
                'event' => $event,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'dedup_key' => $dedupKey,
                'status' => NotificationOutbox::STATUS_PENDING,
                'attempts' => 0,
                'available_at' => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                Log::debug('Push outbox dedup skip', ['event' => $event, 'dedup_key' => $dedupKey]);

                return null;
            }

            throw $exception;
        }
    }

    private function isUniqueViolation(\Throwable $exception): bool
    {
        $code = method_exists($exception, 'getCode') ? $exception->getCode() : null;

        if ($code === '23505' || (is_string($code) && str_contains($code, '23505'))) {
            return true;
        }

        return str_contains($exception->getMessage(), 'duplicate key value');
    }
}
