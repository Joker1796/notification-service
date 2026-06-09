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
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Flush Redis between tests so idempotency keys from one test
        // do not bleed into the next (RefreshDatabase only resets the DB).
        Redis::flushdb();
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
        $this->app->instance(MockSmsProvider::class, new class implements NotificationProviderInterface {
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
        $failingProvider = new class implements NotificationProviderInterface {
            public function send(int $subscriberId, string $message): void
            {
                throw new TemporaryProviderException('Provider down');
            }
        };

        $this->app->instance(MockEmailProvider::class, $failingProvider);

        // Attempt 1
        try { $job->handle(); } catch (TemporaryProviderException) {}
        $notification->refresh();
        $this->assertEquals(NotificationStatus::Queued, $notification->status);
        $this->assertEquals(1, $notification->retry_count);

        // Attempt 2
        try { $job->handle(); } catch (TemporaryProviderException) {}
        $notification->refresh();
        $this->assertEquals(2, $notification->retry_count);

        // Attempt 3
        try { $job->handle(); } catch (TemporaryProviderException) {}
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
        $this->app->instance(MockSmsProvider::class, new class ($ref) implements NotificationProviderInterface {
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
}
