<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class DeduplicationService
{
    private const TTL = 86400; // 24 hours
    private const PREFIX = 'idem:';

    /**
     * Single Redis GET replaces the previous separate EXISTS + GET pair,
     * eliminating the TOCTOU window between the two calls.
     */
    public function findBatchId(string $idempotencyKey): ?string
    {
        return Redis::get(self::PREFIX . $idempotencyKey) ?: null;
    }

    public function register(string $idempotencyKey, string $batchId): void
    {
        Redis::setex(self::PREFIX . $idempotencyKey, self::TTL, $batchId);
    }

    /**
     * Remove only keys owned by this service (used in tests instead of flushdb).
     * Uses SCAN instead of KEYS to avoid blocking Redis on large keyspaces.
     */
    public function flush(): void
    {
        $cursor = 0;
        do {
            [$cursor, $keys] = Redis::scan($cursor, ['match' => self::PREFIX . '*', 'count' => 100]);
            if (!empty($keys)) {
                Redis::del($keys);
            }
        } while ((int) $cursor !== 0);
    }
}
