<?php

namespace App\Enums;

enum NotificationBatchStatus: string
{
    case Processing    = 'processing';
    case Completed     = 'completed';
    case PartialFailure = 'partial_failure';
}
