<?php

namespace Tests\Integration;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecoverStuckNotificationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeBatch(string $idempotencyKey): NotificationBatch
    {
        return NotificationBatch::create([
            'id'              => Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'channel'         => NotificationChannel::Sms->value,
            'type'            => NotificationType::Transactional->value,
            'message'         => 'Test',
            'status'          => 'processing',
            'total_count'     => 1,
        ]);
    }

    private function makeNotification(string $batchId, NotificationStatus $status): Notification
    {
        return Notification::create([
            'id'            => Str::uuid(),
            'batch_id'      => $batchId,
            'subscriber_id' => 1,
            'channel'       => NotificationChannel::Sms->value,
            'type'          => NotificationType::Transactional->value,
            'message'       => 'Test',
            'status'        => $status->value,
        ]);
    }

    // --- Phase 1: reset notifications stuck in 'sent' ---

    public function test_phase1_resets_sent_notifications_older_than_threshold(): void
    {
        $batch        = $this->makeBatch('recover-p1-001');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Sent);

        // Simulate a crash 10 minutes ago by backdating updated_at.
        Notification::where('id', $notification->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])
            ->assertExitCode(0);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Queued, $notification->status);
        $this->assertStringContainsString('stuck', $notification->last_error);
    }

    public function test_phase1_does_not_reset_sent_notifications_newer_than_threshold(): void
    {
        $batch        = $this->makeBatch('recover-p1-002');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Sent);
        // updated_at is recent (just now), so it should NOT be reset.

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])
            ->assertExitCode(0);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Sent, $notification->status);
    }

    public function test_phase1_dry_run_does_not_make_changes(): void
    {
        $batch        = $this->makeBatch('recover-p1-003');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Sent);

        Notification::where('id', $notification->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5, '--dry-run' => true])
            ->assertExitCode(0);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Sent, $notification->status);
    }

    // --- Phase 2: re-dispatch orphaned 'queued' notifications ---

    public function test_phase2_redispatches_queued_notifications_older_than_threshold(): void
    {
        Queue::fake();

        $batch        = $this->makeBatch('recover-p2-001');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Queued);

        Notification::where('id', $notification->id)->update(['created_at' => now()->subMinutes(10)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])
            ->assertExitCode(0);

        Queue::assertPushed(ProcessNotificationJob::class);
    }

    public function test_phase2_does_not_redispatch_recent_queued_notifications(): void
    {
        Queue::fake();

        $batch        = $this->makeBatch('recover-p2-002');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Queued);
        // created_at is recent, should NOT be re-dispatched.

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_phase2_dry_run_does_not_dispatch(): void
    {
        Queue::fake();

        $batch        = $this->makeBatch('recover-p2-003');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Queued);

        Notification::where('id', $notification->id)->update(['created_at' => now()->subMinutes(10)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5, '--dry-run' => true])
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_command_reports_no_work_when_all_notifications_are_fresh(): void
    {
        $this->artisan('notifications:recover-stuck')
            ->expectsOutput('Phase 1: no notifications stuck in "sent".')
            ->expectsOutput('Phase 2: no orphaned "queued" notifications.')
            ->assertExitCode(0);
    }

    // --- --minutes threshold ---

    public function test_custom_minutes_threshold_is_respected(): void
    {
        $batch        = $this->makeBatch('recover-minutes-001');
        $notification = $this->makeNotification($batch->id, NotificationStatus::Sent);

        // 3 minutes old — below threshold of 5, must NOT be recovered.
        Notification::where('id', $notification->id)->update(['updated_at' => now()->subMinutes(3)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])->assertExitCode(0);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Sent, $notification->status);

        // Same notification but now threshold is 2 — must be recovered.
        $this->artisan('notifications:recover-stuck', ['--minutes' => 2])->assertExitCode(0);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Queued, $notification->status);
    }

    // --- Queue routing ---

    public function test_phase2_redispatches_to_correct_queue_by_notification_type(): void
    {
        Queue::fake();

        $batch = NotificationBatch::create([
            'id'              => Str::uuid(),
            'idempotency_key' => 'recover-queue-001',
            'channel'         => NotificationChannel::Email->value,
            'type'            => NotificationType::Marketing->value,
            'message'         => 'Sale!',
            'status'          => 'processing',
            'total_count'     => 1,
        ]);

        $notification = Notification::create([
            'id'            => Str::uuid(),
            'batch_id'      => $batch->id,
            'subscriber_id' => 1,
            'channel'       => NotificationChannel::Email->value,
            'type'          => NotificationType::Marketing->value,
            'message'       => 'Sale!',
            'status'        => NotificationStatus::Queued->value,
        ]);

        Notification::where('id', $notification->id)->update(['created_at' => now()->subMinutes(10)]);

        $this->artisan('notifications:recover-stuck', ['--minutes' => 5])->assertExitCode(0);

        Queue::assertPushedOn('notifications.marketing', ProcessNotificationJob::class);
    }
}
