<?php

namespace App\Providers\Notification;

use App\Enums\NotificationChannel;

class NotificationProviderFactory
{
    public function __construct(
        private readonly MockSmsProvider   $smsProvider,
        private readonly MockEmailProvider $emailProvider,
    ) {}

    public function make(NotificationChannel $channel): NotificationProviderInterface
    {
        return match ($channel) {
            NotificationChannel::Sms   => $this->smsProvider,
            NotificationChannel::Email => $this->emailProvider,
        };
    }
}
