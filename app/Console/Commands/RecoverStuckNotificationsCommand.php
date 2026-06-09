<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Console\Command;

class RecoverStuckNotificationsCommand extends Command
{
    protected $signature = 'notifications:recover-stuck
                            {--minutes=5 : Notifications stuck in "sent" for this many minutes are reset}
                            {--dry-run : Show what would be reset without actually doing it}';

    protected $description = 'Reset notifications stuck in "sent" status (worker crashed mid-flight) back to "queued"';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $dryRun = $this->option('dry-run');

        $query = Notification::where('status', NotificationStatus::Sent)
            ->where('updated_at', '<', now()->subMinutes($minutes));

        $count = $query->count();

        if ($count === 0) {
            $this->info('No stuck notifications found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[DRY RUN] Would reset {$count} notification(s) stuck in 'sent' for more than {$minutes} minute(s).");

            return self::SUCCESS;
        }

        $query->update([
            'status' => NotificationStatus::Queued,
            'last_error' => "Recovered from stuck 'sent' state by scheduler",
        ]);

        $this->info("Reset {$count} stuck notification(s) back to 'queued'.");

        return self::SUCCESS;
    }
}
