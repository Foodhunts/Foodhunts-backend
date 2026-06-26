<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public function registerToken(User $user, array $data): array
    {
        $token = PushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $user->id,
                'platform' => $data['platform'],
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

        return ['push_token' => $token];
    }

    public function notifyOrderPlaced(Order $order): void
    {
        Log::info('Push notification queued', ['type' => 'order_placed', 'order_id' => $order->id]);
    }

    public function notifyOrderStatusChanged(Order $order): void
    {
        Log::info('Push notification queued', ['type' => 'order_status_changed', 'order_id' => $order->id]);
    }
}
