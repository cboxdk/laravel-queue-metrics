<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories\Concerns;

use Illuminate\Support\Facades\Redis;
use Illuminate\Redis\Connections\Connection;

/**
 * Shared Redis interaction methods for repositories.
 */
trait InteractsWithRedis
{
    protected function getRedis(): Connection
    {
        $connection = config('queue-metrics.storage.redis.connection', 'default');

        /** @var Connection */
        return Redis::connection($connection);
    }

    protected function getPrefix(): string
    {
        return config('queue-metrics.storage.redis.prefix', 'queue_metrics');
    }

    protected function key(string ...$parts): string
    {
        return $this->getPrefix() . ':' . implode(':', $parts);
    }

    protected function getTtl(string $type): int
    {
        return (int) config("queue-metrics.storage.redis.ttl.{$type}", 3600);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    protected function hmset(string $key, array $data, ?int $ttl = null): void
    {
        $redis = $this->getRedis();
        $redis->hmset($key, $data);

        if ($ttl !== null) {
            $redis->expire($key, $ttl);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function hgetall(string $key): array
    {
        /** @var array<string, string> */
        return $this->getRedis()->hgetall($key) ?: [];
    }

    /**
     * @param array<string> $keys
     */
    protected function deleteKeys(array $keys): int
    {
        if (empty($keys)) {
            return 0;
        }

        return (int) $this->getRedis()->del(...$keys);
    }

    /**
     * @return array<string>
     */
    protected function scanKeys(string $pattern): array
    {
        $redis = $this->getRedis();
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} */
            $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
            [$cursor, $found] = $result;
            $keys = array_merge($keys, $found);
        } while ($cursor !== '0');

        return $keys;
    }
}
