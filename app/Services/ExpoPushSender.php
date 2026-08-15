<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Facades\Http;

class ExpoPushSender
{
    public const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    private const MAX_MESSAGES_PER_REQUEST = 100;

    /**
     * Send a push to every active token for a user, chunked to Expo's limit.
     *
     * @param  array{title:string, body:string, data:array, sound?:string}  $payload
     * @return array{ok:bool, sent:int, failed:int, skipped:bool, error:?string}
     */
    public function sendToUser(?string $userId, array $payload): array
    {
        if ($userId === null) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => true, 'error' => null];
        }

        $tokens = PushToken::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get(['id', 'expo_push_token', 'failure_count']);

        if ($tokens->isEmpty()) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => true, 'error' => null];
        }

        $messages = $tokens->map(fn (PushToken $t) => [
            'to' => $t->expo_push_token,
            'title' => $payload['title'],
            'body' => $payload['body'],
            'sound' => $payload['sound'] ?? 'default',
            'data' => $payload['data'] ?? [],
            'priority' => 'high',
            'channelId' => 'default',
        ])->all();

        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach (array_chunk($messages, self::MAX_MESSAGES_PER_REQUEST) as $chunk) {
            $request = Http::timeout(10)->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

            $accessToken = (string) config('services.expo.access_token');
            if ($accessToken !== '') {
                $request = $request->withToken($accessToken);
            }

            $response = $request->post(self::EXPO_PUSH_URL, $chunk);

            $result = $response->json();
            $receipts = is_array($result['data'] ?? null)
                ? $result['data']
                : (is_array($result) ? $result : []);

            if (! $response->successful()) {
                $lastError = $result['message'] ?? "Expo push failed with status {$response->status()}";
            }

            foreach ($chunk as $index => $message) {
                $receipt = $receipts[$index] ?? null;
                $token = $tokens->firstWhere('expo_push_token', $message['to']);

                if (($receipt['status'] ?? null) === 'ok') {
                    $sent++;
                    $this->markTokenSuccess($token);
                } else {
                    $failed++;
                    $error = $receipt['details']['error'] ?? ($receipt['message'] ?? 'Expo push error');
                    $lastError = $error;
                    $this->markTokenFailure($token, $error);
                }
            }
        }

        return [
            'ok' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => false,
            'error' => $sent > 0 ? null : $lastError,
        ];
    }

    private function markTokenSuccess(?PushToken $token): void
    {
        if (! $token) {
            return;
        }

        $token->forceFill([
            'is_active' => true,
            'failure_count' => 0,
            'error_code' => null,
            'error_message' => null,
            'last_success_at' => now(),
            'last_seen_at' => now(),
        ])->save();
    }

    private function markTokenFailure(?PushToken $token, string $error): void
    {
        if (! $token) {
            return;
        }

        $isUnregistered = $error === 'DeviceNotRegistered';

        $token->forceFill([
            'is_active' => $isUnregistered ? false : $token->is_active,
            'failure_count' => ($token->failure_count ?? 0) + 1,
            'error_code' => $error,
            'error_message' => $error,
            'last_failure_at' => now(),
            'last_seen_at' => now(),
        ])->save();
    }
}
