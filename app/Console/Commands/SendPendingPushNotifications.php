<?php

namespace App\Console\Commands;

use App\Models\NotificationOutbox;
use App\Services\ExpoPushSender;
use Illuminate\Console\Command;

class SendPendingPushNotifications extends Command
{
    protected $signature = 'push:send-pending {--limit=200}';

    protected $description = 'Drain the notification_outbox and send pending pushes via Expo';

    public function handle(ExpoPushSender $sender): int
    {
        $limit = (int) $this->option('limit');

        $rows = NotificationOutbox::query()
            ->where('status', NotificationOutbox::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            // Atomic single-winner claim: only one worker flips pending → sending.
            $claimed = NotificationOutbox::query()
                ->whereKey($row->id)
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->update([
                    'status' => NotificationOutbox::STATUS_SENDING,
                    'locked_at' => now(),
                ]);

            if ($claimed !== 1) {
                continue;
            }

            $result = $this->deliver($sender, $row);

            if ($result['ok']) {
                NotificationOutbox::query()->whereKey($row->id)->update([
                    'status' => NotificationOutbox::STATUS_SENT,
                    'sent_at' => now(),
                    'error' => null,
                    'locked_at' => null,
                ]);
                $sent++;
            } else {
                $failed++;
                $attempts = ($row->attempts ?? 0) + 1;
                $exceeded = $attempts >= NotificationOutbox::MAX_ATTEMPTS;

                NotificationOutbox::query()->whereKey($row->id)->update([
                    'status' => $exceeded ? NotificationOutbox::STATUS_DEAD : NotificationOutbox::STATUS_PENDING,
                    'attempts' => $attempts,
                    'available_at' => $exceeded ? null : now()->addSeconds(min(60 * (2 ** $attempts), 1800)),
                    'locked_at' => null,
                    'error' => $result['error'] ?? 'Push failed',
                ]);
            }
        }

        $this->info("Push outbox drain complete: {$sent} sent, {$failed} failed (of {$rows->count()} claimed).");

        return 0;
    }

    private function deliver(ExpoPushSender $sender, NotificationOutbox $row): array
    {
        if (! $row->user_id) {
            return ['ok' => true, 'sent' => 0, 'failed' => 0, 'skipped' => true, 'error' => null];
        }

        return $sender->sendToUser($row->user_id, [
            'title' => $row->title,
            'body' => $row->body,
            'data' => $row->data ?? [],
        ]);
    }
}
