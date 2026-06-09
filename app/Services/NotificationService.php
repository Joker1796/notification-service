<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationService
{
    public function __construct(private readonly DeduplicationService $deduplication) {}

    /**
     * @param  array<int>  $recipientIds
     */
    public function dispatchBulk(
        NotificationChannel $channel,
        NotificationType $type,
        string $message,
        string $idempotencyKey,
        array $recipientIds,
    ): NotificationBatch {
        // Fast-path: Redis cache hit (avoids a DB write on duplicates)
        if ($this->deduplication->isDuplicate($idempotencyKey)) {
            $batchId = $this->deduplication->getBatchId($idempotencyKey);

            return NotificationBatch::findOrFail($batchId);
        }

        $batch = null;

        try {
            // DB::transaction ensures batch + notifications are created atomically.
            // The unique index on idempotency_key is the authoritative idempotency guard.
            DB::transaction(function () use ($channel, $type, $message, $idempotencyKey, $recipientIds, &$batch) {
                $batch = NotificationBatch::create([
                    'id' => Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'channel' => $channel,
                    'type' => $type,
                    'message' => $message,
                    'status' => 'processing',
                    'total_count' => count($recipientIds),
                ]);

                foreach ($recipientIds as $subscriberId) {
                    Notification::create([
                        'id' => Str::uuid(),
                        'batch_id' => $batch->id,
                        'subscriber_id' => $subscriberId,
                        'channel' => $channel,
                        'type' => $type,
                        'message' => $message,
                        'status' => NotificationStatus::Queued,
                    ]);
                }
            });
        } catch (QueryException $e) {
            // Two concurrent requests with the same idempotency_key: the DB unique
            // constraint catches the race that Redis check alone cannot prevent.
            if ($e->getCode() === '23505') {
                return NotificationBatch::where('idempotency_key', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        // Register in Redis BEFORE dispatching jobs so that any crash between
        // dispatch and here does not leave a window where the next request creates
        // a duplicate batch (the DB constraint protects, but Redis prevents the query).
        $this->deduplication->register($idempotencyKey, $batch->id);

        $queue = $type === NotificationType::Transactional
            ? 'notifications.transactional'
            : 'notifications.marketing';

        $connection = $type === NotificationType::Transactional
            ? 'rabbitmq_high'
            : 'rabbitmq_low';

        // Load notifications created inside the transaction and dispatch them.
        foreach ($batch->notifications as $notification) {
            ProcessNotificationJob::dispatch($notification)
                ->onConnection($connection)
                ->onQueue($queue);
        }

        return $batch;
    }

    public function getSubscriberNotifications(int $subscriberId, int $perPage = 50): LengthAwarePaginator
    {
        return Notification::where('subscriber_id', $subscriberId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
