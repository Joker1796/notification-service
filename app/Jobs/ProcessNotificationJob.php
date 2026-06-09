<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Exceptions\TemporaryProviderException;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Providers\Notification\NotificationProviderFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(private readonly Notification $notification) {}

    /** Exponential backoff: 30 s → 60 s → 120 s between attempts. */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(NotificationProviderFactory $factory): void
    {
        // Atomic exactly-once claim: single UPDATE with WHERE status='queued'.
        // If two workers race, only one gets affectedRows=1; the other exits here.
        $claimed = Notification::where('id', $this->notification->id)
            ->where('status', NotificationStatus::Queued)
            ->update(['status' => NotificationStatus::Sent]);

        if ($claimed === 0) {
            return;
        }

        $this->notification->refresh();

        try {
            $factory->make($this->notification->channel)
                ->send($this->notification->subscriber_id, $this->notification->message);

            $this->notification->update(['status' => NotificationStatus::Delivered]);
            NotificationBatch::where('id', $this->notification->batch_id)->increment('completed_count');
            $this->finalizeBatchIfComplete();
        } catch (TemporaryProviderException $e) {
            // Single UPDATE keeps retry_count and status consistent even under DB failures.
            $this->notification->update([
                'status'      => NotificationStatus::Queued,
                'retry_count' => DB::raw('retry_count + 1'),
                'last_error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->notification->update([
            'status'     => NotificationStatus::Discarded,
            'last_error' => $e->getMessage(),
        ]);
        NotificationBatch::where('id', $this->notification->batch_id)->increment('failed_count');
        $this->finalizeBatchIfComplete();
    }

    private function finalizeBatchIfComplete(): void
    {
        // Single atomic UPDATE: only runs when all notifications in the batch are done.
        NotificationBatch::where('id', $this->notification->batch_id)
            ->whereRaw('completed_count + failed_count = total_count')
            ->whereNotIn('status', ['completed', 'partial_failure'])
            ->update([
                'status' => DB::raw("CASE WHEN failed_count > 0 THEN 'partial_failure' ELSE 'completed' END"),
            ]);
    }
}
