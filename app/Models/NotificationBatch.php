<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'idempotency_key',
        'channel',
        'type',
        'message',
        'status',
        'total_count',
        'completed_count',
        'failed_count',
    ];

    protected $casts = [
        'channel' => NotificationChannel::class,
        'type' => NotificationType::class,
    ];

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }
}
