<?php

namespace App\Services;

use App\Enums\NotificationBatchStatus;
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
        // Fast-path: single Redis GET (replaces separate EXISTS + GET calls).
        $existingBatchId = $this->deduplication->findBatchId($idempotencyKey);
        if ($existingBatchId !== null) {
            // Use find() instead of findOrFail() — a stale Redis entry (batch deleted)
            // must not throw a 404; fall through to create a new batch instead.
            $existing = NotificationBatch::find($existingBatchId);
            if ($existing !== null) {
                return $existing;
            }
        }

        // Deduplicate recipient list before counting and inserting.
        $recipientIds = array_values(array_unique($recipientIds));

        $batch = null;

        try {
            // DB::transaction ensures batch + notifications are created atomically.
            // The unique index on idempotency_key is the authoritative idempotency guard.
            DB::transaction(function () use ($channel, $type, $message, $idempotencyKey, $recipientIds, &$batch) {
                $batch = NotificationBatch::create([
                    'id'              => Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'channel'         => $channel,
                    'type'            => $type,
                    'message'         => $message,
                    'status'          => NotificationBatchStatus::Processing,
                    'total_count'     => count($recipientIds),
                ]);

                // Bulk-insert all notifications in chunks of 500 to avoid hitting
                // PostgreSQL's parameter limit and to keep INSERT statements compact.
                $now  = now();
                $rows = array_map(fn (int $subscriberId) => [
                    'id'            => (string) Str::uuid(),
                    'batch_id'      => $batch->id,
                    'subscriber_id' => $subscriberId,
                    'channel'       => $channel->value,
                    'type'          => $type->value,
                    'message'       => $message,
                    'status'        => NotificationStatus::Queued->value,
                    'retry_count'   => 0,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ], $recipientIds);

                foreach (array_chunk($rows, 500) as $chunk) {
                    Notification::insert($chunk);
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

        // Register in Redis after the transaction so that duplicate requests get the
        // fast-path on subsequent calls (DB constraint is the authoritative guard).
        $this->deduplication->register($idempotencyKey, $batch->id);

        // Use cursor() to stream notifications one-by-one, avoiding loading all
        // Eloquent models into memory at once (up to 10 000 rows).
        $batch->notifications()->cursor()->each(
            fn (Notification $notification) => ProcessNotificationJob::dispatch($notification)
                ->onConnection($type->queueConnection())
                ->onQueue($type->queueName())
        );

        return $batch;
    }

    public function getSubscriberNotifications(int $subscriberId, int $perPage = 50): LengthAwarePaginator
    {
        return Notification::where('subscriber_id', $subscriberId)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
