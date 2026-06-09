<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class DeduplicationService
{
    private const TTL = 86400; // 24 hours
    private const PREFIX = 'idem:';

    public function isDuplicate(string $idempotencyKey): bool
    {
        return (bool) Redis::exists(self::PREFIX . $idempotencyKey);
    }

    public function getBatchId(string $idempotencyKey): ?string
    {
        return Redis::get(self::PREFIX . $idempotencyKey) ?: null;
    }

    public function register(string $idempotencyKey, string $batchId): void
    {
        Redis::setex(self::PREFIX . $idempotencyKey, self::TTL, $batchId);
    }
}
