<?php

namespace App\Enums;

enum NotificationType: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function queueConnection(): string
    {
        return match ($this) {
            self::Transactional => 'rabbitmq_high',
            self::Marketing     => 'rabbitmq_low',
        };
    }

    public function queueName(): string
    {
        return match ($this) {
            self::Transactional => 'notifications.transactional',
            self::Marketing     => 'notifications.marketing',
        };
    }
}
