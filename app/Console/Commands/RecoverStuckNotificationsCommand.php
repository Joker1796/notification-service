<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;

class RecoverStuckNotificationsCommand extends Command
{
    protected $signature = 'notifications:recover-stuck
                            {--minutes=5 : Age threshold in minutes for both recovery phases}
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Two-phase recovery: reset "sent" notifications whose worker crashed; re-dispatch "queued" notifications that were never sent to RabbitMQ';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun  = (bool) $this->option('dry-run');

        $this->recoverStuckInSent($minutes, $dryRun);
        $this->recoverOrphanedQueued($minutes, $dryRun);

        return self::SUCCESS;
    }

    private function recoverStuckInSent(int $minutes, bool $dryRun): void
    {
        $query = Notification::where('status', NotificationStatus::Sent)
            ->where('updated_at', '<', now()->subMinutes($minutes));

        $count = $query->count();

        if ($count === 0) {
            $this->info('Phase 1: no notifications stuck in "sent".');
            return;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] Phase 1: would reset {$count} notification(s) from 'sent' back to 'queued'.");
            return;
        }

        $query->update([
            'status'     => NotificationStatus::Queued,
            'last_error' => "Recovered from stuck 'sent' state by scheduler",
        ]);

        $this->info("Phase 1: reset {$count} notification(s) from 'sent' back to 'queued'.");
    }

    private function recoverOrphanedQueued(int $minutes, bool $dryRun): void
    {
        // Notifications sitting in 'queued' longer than the threshold have no
        // corresponding RabbitMQ job (app crashed between DB commit and dispatch).
        // Re-dispatching is safe: the job's atomic claim (WHERE status='queued')
        // prevents double-sends even if a duplicate job somehow exists in the queue.
        $query = Notification::where('status', NotificationStatus::Queued)
            ->where('created_at', '<', now()->subMinutes($minutes));

        $count = $query->count();

        if ($count === 0) {
            $this->info('Phase 2: no orphaned "queued" notifications.');
            return;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] Phase 2: would re-dispatch {$count} orphaned 'queued' notification(s).");
            return;
        }

        $query->cursor()->each(function (Notification $notification) {
            ProcessNotificationJob::dispatch($notification)
                ->onConnection($notification->type->queueConnection())
                ->onQueue($notification->type->queueName());
        });

        $this->info("Phase 2: re-dispatched {$count} orphaned 'queued' notification(s).");
    }
}
