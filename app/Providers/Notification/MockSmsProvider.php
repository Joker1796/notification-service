<?php

namespace App\Providers\Notification;

use App\Exceptions\TemporaryProviderException;
use Illuminate\Support\Facades\Log;

class MockSmsProvider implements NotificationProviderInterface
{
    public function send(int $subscriberId, string $message): void
    {
        // Simulate 20% gateway failure rate
        if (random_int(1, 10) > 8) {
            throw new TemporaryProviderException('SMS gateway timeout');
        }

        Log::info('MockSmsProvider: message sent', [
            'subscriber_id' => $subscriberId,
            'message_preview' => mb_substr($message, 0, 50),
        ]);
    }
}
