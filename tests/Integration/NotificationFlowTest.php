<?php

namespace Tests\Integration;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Exceptions\TemporaryProviderException;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Providers\Notification\MockEmailProvider;
use App\Providers\Notification\MockSmsProvider;
use App\Providers\Notification\NotificationProviderInterface;
use App\Services\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush only the DeduplicationService's own Redis keys between tests
        // so idempotency state from one test does not bleed into the next.
        // Using flush() instead of flushdb() avoids wiping data from other
        // processes that may share the same Redis instance in CI.
        app(DeduplicationService::class)->flush();
        // Attach the API key header to all test requests automatically.
        $this->withHeaders(['X-Api-Key' => config('app.api_key')]);
    }

    // --- Test 1: Bulk dispatch creates notifications with queued status ---

    public function test_bulk_dispatch_creates_notifications_in_queued_status(): void
    {
        $response = $this->postJson('/api/v1/notifications/bulk', [
            'channel' => 'sms',
            'type' => 'transactional',
            'message' => 'Ваш код: 9999',
            'idempotency_key' => 'test-key-001',
            'recipient_ids' => [101, 102, 103],
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['batch_id', 'accepted_count', 'status'])
            ->assertJson(['accepted_count' => 3, 'status' => 'processing']);

        $batchId = $response->json('batch_id');

        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 3);

        $this->assertDatabaseHas('notification_batches', [
            'id' => $batchId,
            'idempotency_key' => 'test-key-001',
            'channel' => 'sms',
            'type' => 'transactional',
        ]);

        Notification::where('batch_id', $batchId)->each(function (Notification $n) {
            $this->assertEquals(NotificationStatus::Queued, $n->status);
        });
    }

    // --- Test 2: Job processing changes status to delivered ---

    public function test_job_processes_notification_and_sets_delivered_status(): void
    {
        // Job resolves app(MockSmsProvider::class) directly — bind the concrete class.
        $this->app->instance(MockSmsProvider::class, new class extends MockSmsProvider {
            public function send(int $subscriberId, string $message): void {}
        });

        $batch = NotificationBatch::create([
            'id' => Str::uuid(),
            'idempotency_key' => 'test-key-job-001',
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Test message',
            'status' => 'processing',
            'total_count' => 1,
        ]);

        $notification = Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 42,
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Test message',
            'status' => NotificationStatus::Queued->value,
        ]);

        ProcessNotificationJob::dispatchSync($notification);

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Delivered, $notification->status);
    }

    // --- Test 3: Retry on provider failure, discarded after max attempts ---

    public function test_notification_is_discarded_after_max_retries(): void
    {
        $batch = NotificationBatch::create([
            'id' => Str::uuid(),
            'idempotency_key' => 'test-key-retry-001',
            'channel' => NotificationChannel::Email->value,
            'type' => NotificationType::Marketing->value,
            'message' => 'Marketing message',
            'status' => 'processing',
            'total_count' => 1,
        ]);

        $notification = Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 99,
            'channel' => NotificationChannel::Email->value,
            'type' => NotificationType::Marketing->value,
            'message' => 'Marketing message',
            'status' => NotificationStatus::Queued->value,
        ]);

        $job = new ProcessNotificationJob($notification);

        // Simulate 3 failed attempts then call failed().
        // Job resolves app(MockEmailProvider::class) directly — bind the concrete class.
        $failingProvider = new class extends MockEmailProvider {
            public function send(int $subscriberId, string $message): void
            {
                throw new TemporaryProviderException('Provider down');
            }
        };

        $this->app->instance(MockEmailProvider::class, $failingProvider);

        // app()->call() triggers DI injection for handle(NotificationProviderFactory $factory).
        // Attempt 1
        try { app()->call([$job, 'handle']); } catch (TemporaryProviderException) {}
        $notification->refresh();
        $this->assertEquals(NotificationStatus::Queued, $notification->status);
        $this->assertEquals(1, $notification->retry_count);

        // Attempt 2
        try { app()->call([$job, 'handle']); } catch (TemporaryProviderException) {}
        $notification->refresh();
        $this->assertEquals(2, $notification->retry_count);

        // Attempt 3
        try { app()->call([$job, 'handle']); } catch (TemporaryProviderException) {}
        $notification->refresh();
        $this->assertEquals(3, $notification->retry_count);

        // Max attempts exhausted — failed() is called by Laravel queue
        $job->failed(new TemporaryProviderException('Provider down'));

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Discarded, $notification->status);
        $this->assertEquals('Provider down', $notification->last_error);
    }

    // --- Test 4: Idempotency — duplicate request returns same batch ---

    public function test_duplicate_idempotency_key_returns_same_batch(): void
    {
        $payload = [
            'channel' => 'email',
            'type' => 'marketing',
            'message' => 'Hello!',
            'idempotency_key' => 'unique-idem-key-xyz',
            'recipient_ids' => [1, 2],
        ];

        $first = $this->postJson('/api/v1/notifications/bulk', $payload);
        $second = $this->postJson('/api/v1/notifications/bulk', $payload);

        $first->assertStatus(202);
        $second->assertStatus(202);

        $this->assertEquals($first->json('batch_id'), $second->json('batch_id'));
        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    // --- Test 5: Transactional jobs go to transactional queue ---

    public function test_transactional_notifications_dispatched_to_transactional_queue(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson('/api/v1/notifications/bulk', [
            'channel' => 'sms',
            'type' => 'transactional',
            'message' => 'Code: 1234',
            'idempotency_key' => 'queue-test-001',
            'recipient_ids' => [10],
        ])->assertStatus(202);

        \Illuminate\Support\Facades\Queue::assertPushedOn(
            'notifications.transactional',
            ProcessNotificationJob::class,
        );
    }

    public function test_marketing_notifications_dispatched_to_marketing_queue(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson('/api/v1/notifications/bulk', [
            'channel' => 'email',
            'type' => 'marketing',
            'message' => 'Sale!',
            'idempotency_key' => 'queue-test-002',
            'recipient_ids' => [20],
        ])->assertStatus(202);

        \Illuminate\Support\Facades\Queue::assertPushedOn(
            'notifications.marketing',
            ProcessNotificationJob::class,
        );
    }

    // --- Test 6: GET subscriber notifications ---

    public function test_get_subscriber_notifications_returns_correct_data(): void
    {
        $batch = NotificationBatch::create([
            'id' => Str::uuid(),
            'idempotency_key' => 'test-get-001',
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Hello subscriber',
            'status' => 'processing',
            'total_count' => 2,
        ]);

        Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 555,
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Hello subscriber',
            'status' => NotificationStatus::Delivered->value,
        ]);

        Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 555,
            'channel' => NotificationChannel::Email->value,
            'type' => NotificationType::Marketing->value,
            'message' => 'Marketing offer',
            'status' => NotificationStatus::Discarded->value,
        ]);

        // Notification for a different subscriber (should not appear)
        Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 999,
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Other subscriber',
            'status' => NotificationStatus::Queued->value,
        ]);

        $response = $this->getJson('/api/v1/subscribers/555/notifications');

        $response->assertStatus(200)
            ->assertJson([
                'subscriber_id' => 555,
                'meta' => ['total' => 2, 'current_page' => 1],
            ])
            ->assertJsonStructure([
                'subscriber_id',
                'data' => [
                    '*' => ['id', 'batch_id', 'channel', 'type', 'message', 'status', 'retry_count', 'created_at', 'updated_at'],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $statuses = collect($response->json('data'))->pluck('status')->toArray();
        $this->assertContains('delivered', $statuses);
        $this->assertContains('discarded', $statuses);
    }

    // --- Test 7: Exactly-once guard in Job ---

    public function test_job_skips_already_processed_notification(): void
    {
        $providerCalled = false;

        // Job resolves app(MockSmsProvider::class) directly — bind the concrete class.
        // Pass $providerCalled by reference through a wrapper so the closure can
        // mutate the outer variable (anonymous class constructors accept references).
        $ref = &$providerCalled;
        $this->app->instance(MockSmsProvider::class, new class ($ref) extends MockSmsProvider {
            public function __construct(private bool &$called) {}

            public function send(int $subscriberId, string $message): void
            {
                $this->called = true;
            }
        });

        $batch = NotificationBatch::create([
            'id' => Str::uuid(),
            'idempotency_key' => 'test-exactly-once',
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Test',
            'status' => 'processing',
            'total_count' => 1,
        ]);

        $notification = Notification::create([
            'id' => Str::uuid(),
            'batch_id' => $batch->id,
            'subscriber_id' => 1,
            'channel' => NotificationChannel::Sms->value,
            'type' => NotificationType::Transactional->value,
            'message' => 'Test',
            'status' => NotificationStatus::Delivered->value, // Already processed
        ]);

        ProcessNotificationJob::dispatchSync($notification);

        $this->assertFalse($providerCalled, 'Provider should not be called for already-processed notification');

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Delivered, $notification->status);
    }

    // --- Test 8: API key authentication ---

    public function test_request_without_api_key_returns_401(): void
    {
        $this->withHeaders(['X-Api-Key' => '']); // override setUp header

        $this->getJson('/api/v1/subscribers/1/notifications')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthorized']);

        $this->postJson('/api/v1/notifications/bulk', [
            'channel' => 'sms',
            'type' => 'transactional',
            'message' => 'Test',
            'idempotency_key' => 'no-auth-test',
            'recipient_ids' => [1],
        ])->assertStatus(401);
    }

    public function test_request_with_wrong_api_key_returns_401(): void
    {
        $this->withHeaders(['X-Api-Key' => 'wrong-key']);

        $this->getJson('/api/v1/subscribers/1/notifications')
            ->assertStatus(401);
    }

    // --- Validation ---

    public function test_bulk_request_with_invalid_channel_returns_422(): void
    {
        $this->postJson('/api/v1/notifications/bulk', [
            'channel'         => 'fax',
            'type'            => 'transactional',
            'message'         => 'Test',
            'idempotency_key' => 'val-ch-001',
            'recipient_ids'   => [1],
        ])->assertStatus(422)->assertJsonValidationErrors(['channel']);
    }

    public function test_bulk_request_with_missing_required_fields_returns_422(): void
    {
        $this->postJson('/api/v1/notifications/bulk', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel', 'type', 'message', 'idempotency_key', 'recipient_ids']);
    }

    public function test_bulk_request_with_empty_recipient_ids_returns_422(): void
    {
        $this->postJson('/api/v1/notifications/bulk', [
            'channel'         => 'sms',
            'type'            => 'transactional',
            'message'         => 'Test',
            'idempotency_key' => 'val-empty-001',
            'recipient_ids'   => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient_ids']);
    }

    public function test_bulk_request_with_message_too_long_returns_422(): void
    {
        $this->postJson('/api/v1/notifications/bulk', [
            'channel'         => 'email',
            'type'            => 'marketing',
            'message'         => str_repeat('x', 1001),
            'idempotency_key' => 'val-msg-001',
            'recipient_ids'   => [1],
        ])->assertStatus(422)->assertJsonValidationErrors(['message']);
    }

    public function test_bulk_request_with_duplicate_recipient_ids_returns_422(): void
    {
        $this->postJson('/api/v1/notifications/bulk', [
            'channel'         => 'sms',
            'type'            => 'transactional',
            'message'         => 'Test',
            'idempotency_key' => 'val-dup-001',
            'recipient_ids'   => [1, 2, 2, 3],
        ])->assertStatus(422)->assertJsonValidationErrors(['recipient_ids.2']);
    }

    // --- Subscriber notifications edge cases ---

    public function test_subscriber_with_no_notifications_returns_empty_data(): void
    {
        $this->getJson('/api/v1/subscribers/99999/notifications')
            ->assertStatus(200)
            ->assertJson([
                'subscriber_id' => 99999,
                'data'          => [],
                'meta'          => ['total' => 0, 'current_page' => 1],
            ]);
    }

    public function test_per_page_zero_is_clamped_to_one(): void
    {
        $this->getJson('/api/v1/subscribers/1/notifications?per_page=0')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_per_page_negative_is_clamped_to_one(): void
    {
        $this->getJson('/api/v1/subscribers/1/notifications?per_page=-5')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 1);
    }

    public function test_per_page_over_limit_is_clamped_to_hundred(): void
    {
        $this->getJson('/api/v1/subscribers/1/notifications?per_page=500')
            ->assertStatus(200)
            ->assertJsonPath('meta.per_page', 100);
    }

    // --- Bug-fix verifications ---

    public function test_non_temporary_exception_resets_notification_to_queued(): void
    {
        $this->app->instance(MockSmsProvider::class, new class extends MockSmsProvider {
            public function send(int $subscriberId, string $message): void
            {
                throw new \RuntimeException('Unexpected network error');
            }
        });

        $batch = NotificationBatch::create([
            'id'              => Str::uuid(),
            'idempotency_key' => 'non-temp-ex-001',
            'channel'         => NotificationChannel::Sms->value,
            'type'            => NotificationType::Transactional->value,
            'message'         => 'Test',
            'status'          => 'processing',
            'total_count'     => 1,
        ]);

        $notification = Notification::create([
            'id'            => Str::uuid(),
            'batch_id'      => $batch->id,
            'subscriber_id' => 1,
            'channel'       => NotificationChannel::Sms->value,
            'type'          => NotificationType::Transactional->value,
            'message'       => 'Test',
            'status'        => NotificationStatus::Queued->value,
        ]);

        $job = new ProcessNotificationJob($notification);

        try {
            app()->call([$job, 'handle']);
        } catch (\RuntimeException) {}

        $notification->refresh();
        $this->assertEquals(NotificationStatus::Queued, $notification->status,
            'A non-TemporaryProviderException must reset status to queued so retries are not wasted');
        $this->assertEquals(1, $notification->retry_count);
    }

    public function test_stale_redis_entry_falls_through_to_new_batch(): void
    {
        // Register a batch_id that does not exist in the database.
        app(DeduplicationService::class)->register('stale-key', (string) Str::uuid());

        $response = $this->postJson('/api/v1/notifications/bulk', [
            'channel'         => 'sms',
            'type'            => 'transactional',
            'message'         => 'Test',
            'idempotency_key' => 'stale-key',
            'recipient_ids'   => [1],
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_duplicate_recipient_ids_are_deduplicated_at_service_level(): void
    {
        /** @var \App\Services\NotificationService $service */
        $service = app(\App\Services\NotificationService::class);

        $batch = $service->dispatchBulk(
            channel: NotificationChannel::Sms,
            type: NotificationType::Transactional,
            message: 'Test',
            idempotencyKey: 'dedup-service-001',
            recipientIds: [1, 2, 2, 3, 1],
        );

        $this->assertEquals(3, $batch->total_count);
        $this->assertDatabaseCount('notifications', 3);
    }

    // --- Batch finalization ---

    public function test_batch_is_marked_completed_when_all_notifications_delivered(): void
    {
        $this->app->instance(MockSmsProvider::class, new class extends MockSmsProvider {
            public function send(int $subscriberId, string $message): void {}
        });

        $batch = NotificationBatch::create([
            'id'              => Str::uuid(),
            'idempotency_key' => 'finalize-ok-001',
            'channel'         => NotificationChannel::Sms->value,
            'type'            => NotificationType::Transactional->value,
            'message'         => 'Test',
            'status'          => 'processing',
            'total_count'     => 2,
        ]);

        $n1 = Notification::create([
            'id' => Str::uuid(), 'batch_id' => $batch->id, 'subscriber_id' => 1,
            'channel' => NotificationChannel::Sms->value, 'type' => NotificationType::Transactional->value,
            'message' => 'Test', 'status' => NotificationStatus::Queued->value,
        ]);
        $n2 = Notification::create([
            'id' => Str::uuid(), 'batch_id' => $batch->id, 'subscriber_id' => 2,
            'channel' => NotificationChannel::Sms->value, 'type' => NotificationType::Transactional->value,
            'message' => 'Test', 'status' => NotificationStatus::Queued->value,
        ]);

        ProcessNotificationJob::dispatchSync($n1);
        ProcessNotificationJob::dispatchSync($n2);

        $batch->refresh();
        $this->assertEquals('completed', $batch->status->value);
        $this->assertEquals(2, $batch->completed_count);
        $this->assertEquals(0, $batch->failed_count);
    }

    public function test_batch_is_marked_partial_failure_when_some_notifications_fail(): void
    {
        $this->app->instance(MockSmsProvider::class, new class extends MockSmsProvider {
            public function send(int $subscriberId, string $message): void {}
        });

        $batch = NotificationBatch::create([
            'id'              => Str::uuid(),
            'idempotency_key' => 'finalize-partial-001',
            'channel'         => NotificationChannel::Sms->value,
            'type'            => NotificationType::Transactional->value,
            'message'         => 'Test',
            'status'          => 'processing',
            'total_count'     => 2,
        ]);

        $n1 = Notification::create([
            'id' => Str::uuid(), 'batch_id' => $batch->id, 'subscriber_id' => 1,
            'channel' => NotificationChannel::Sms->value, 'type' => NotificationType::Transactional->value,
            'message' => 'Test', 'status' => NotificationStatus::Queued->value,
        ]);
        $n2 = Notification::create([
            'id' => Str::uuid(), 'batch_id' => $batch->id, 'subscriber_id' => 2,
            'channel' => NotificationChannel::Sms->value, 'type' => NotificationType::Transactional->value,
            'message' => 'Test', 'status' => NotificationStatus::Queued->value,
        ]);

        // n1 succeeds.
        ProcessNotificationJob::dispatchSync($n1);

        // n2 exhausts all retries: call failed() directly, as the queue would after max attempts.
        $job = new ProcessNotificationJob($n2);
        $job->failed(new TemporaryProviderException('Provider down'));

        $batch->refresh();
        $this->assertEquals('partial_failure', $batch->status->value);
        $this->assertEquals(1, $batch->completed_count);
        $this->assertEquals(1, $batch->failed_count);
    }
}
