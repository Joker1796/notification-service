<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class BulkNotificationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'channel' => ['required', new Enum(NotificationChannel::class)],
            'type' => ['required', new Enum(NotificationType::class)],
            'message' => ['required', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'recipient_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }
}
