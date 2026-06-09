<?php

namespace App\Jobs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\TemporaryProviderException;
use App\Models\Notification;
use App\Providers\Notification\MockEmailProvider;
use App\Providers\Notification\MockSmsProvider;
use App\Providers\Notification\NotificationProviderInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private readonly Notification $notification) {}

    public function handle(): void
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
            $provider = $this->resolveProvider();
            $provider->send($this->notification->subscriber_id, $this->notification->message);
            $this->notification->update(['status' => NotificationStatus::Delivered]);
        } catch (TemporaryProviderException $e) {
            // Single UPDATE keeps retry_count and status consistent even under DB failures.
            $this->notification->update([
                'status' => NotificationStatus::Queued,
                'retry_count' => DB::raw('retry_count + 1'),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $this->notification->update([
            'status' => NotificationStatus::Discarded,
            'last_error' => $e->getMessage(),
        ]);
    }

    private function resolveProvider(): NotificationProviderInterface
    {
        return match ($this->notification->channel) {
            NotificationChannel::Sms => app(MockSmsProvider::class),
            NotificationChannel::Email => app(MockEmailProvider::class),
        };
    }
}
