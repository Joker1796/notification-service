<?php

namespace App\Providers\Notification;

interface NotificationProviderInterface
{
    public function send(int $subscriberId, string $message): void;
}
