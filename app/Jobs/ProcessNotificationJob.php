<?php

namespace App\Jobs;

use App\Enums\NotificationBatchStatus;
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

        try {
            $factory->make($this->notification->channel)
                ->send($this->notification->subscriber_id, $this->notification->message);

            $this->notification->update(['status' => NotificationStatus::Delivered]);
            NotificationBatch::where('id', $this->notification->batch_id)->increment('completed_count');
            $this->finalizeBatchIfComplete();
        } catch (Throwable $e) {
            // Use query builder (not model->update) to bypass Eloquent's dirty-flag check.
            // The in-memory model still has status=Queued (from before the claim), so
            // model->update(['status' => Queued]) would see no change and skip the column.
            Notification::where('id', $this->notification->id)->update([
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
        $completed = NotificationBatchStatus::Completed->value;
        $partial   = NotificationBatchStatus::PartialFailure->value;

        // Single atomic UPDATE: only runs when all notifications in the batch are done.
        NotificationBatch::where('id', $this->notification->batch_id)
            ->whereRaw('completed_count + failed_count = total_count')
            ->whereNotIn('status', [$completed, $partial])
            ->update([
                'status' => DB::raw("CASE WHEN failed_count > 0 THEN '{$partial}' ELSE '{$completed}' END"),
            ]);
    }
}
