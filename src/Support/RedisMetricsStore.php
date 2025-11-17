<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Simple wrapper around Laravel's Redis for queue metrics storage.
 * Uses Laravel's Redis connection directly instead of abstraction layer.
 */
final readonly class RedisMetricsStore
{
    private Connection $redis;

    private string $prefix;

    public function __construct()
    {
        $connection = config('queue-metrics.storage.connection', 'default');
        $this->redis = Redis::connection($connection);
        $this->prefix = config('queue-metrics.storage.prefix', 'queue_metrics');
    }

    /**
     * Build a Redis key from segments.
     */
    public function key(string ...$segments): string
    {
        return $this->prefix . ':' . implode(':', $segments);
    }

    /**
     * Get TTL for a given key type.
     */
    public function getTtl(string $type): int
    {
        return config("queue-metrics.storage.ttl.{$type}", 3600);
    }

    /**
     * Get the underlying Redis connection.
     */
    public function connection(): Connection
    {
        return $this->redis;
    }

    /**
     * Return self as driver (for StorageManager compatibility).
     */
    public function driver(): self
    {
        return $this;
    }

    // StorageDriver interface methods

    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $this->redis->hmset($key, $data);

        if ($ttl !== null) {
            $this->redis->expire($key, $ttl);
        }
    }

    public function getHash(string $key): array
    {
        return $this->redis->hgetall($key) ?: [];
    }

    public function getHashField(string $key, string $field): mixed
    {
        return $this->redis->hget($key, $field);
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        if (is_float($value)) {
            $this->redis->hincrbyfloat($key, $field, $value);
        } else {
            $this->redis->hincrby($key, $field, $value);
        }
    }

    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        $this->redis->zadd($key, $membersWithScores);

        if ($ttl !== null) {
            $this->redis->expire($key, $ttl);
        }
    }

    public function getSortedSetByRank(string $key, int $start, int $stop): array
    {
        return $this->redis->zrange($key, $start, $stop);
    }

    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        return $this->redis->zrangebyscore($key, $min, $max);
    }

    public function countSortedSetByScore(string $key, string $min, string $max): int
    {
        return (int) $this->redis->zcount($key, $min, $max);
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): int
    {
        return (int) $this->redis->zremrangebyrank($key, $start, $stop);
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->redis->zrem($key, $member);
    }

    public function addToSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->sadd($key, $members);
        }
    }

    public function getSetMembers(string $key): array
    {
        return $this->redis->smembers($key);
    }

    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->srem($key, $members);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            $this->redis->setex($key, $ttl, $value);
        } else {
            $this->redis->set($key, $value);
        }
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function delete(array|string $keys): int
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }

        if (empty($keys)) {
            return 0;
        }

        return (int) $this->redis->del(...$keys);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->redis->expire($key, $seconds);
    }

    public function scanKeys(string $pattern): array
    {
        // Laravel's Redis connection handles prefix automatically for keys() command
        return $this->redis->command('keys', [$pattern]) ?: [];
    }

    public function pipeline(callable $callback): void
    {
        $this->redis->pipeline(function ($pipe) use ($callback) {
            // Create a wrapper that matches StorageDriver interface
            $wrapper = new class($pipe, $this) implements \PHPeek\LaravelQueueMetrics\Storage\Contracts\StorageDriver {
                public function __construct(
                    private $pipe,
                    private RedisMetricsStore $store,
                ) {}

                // Delegate all StorageDriver methods to the pipe
                public function setHash(string $key, array $data, ?int $ttl = null): void
                {
                    $this->pipe->hmset($key, $data);
                    if ($ttl !== null) {
                        $this->pipe->expire($key, $ttl);
                    }
                }

                public function getHash(string $key): array { return []; }
                public function getHashField(string $key, string $field): mixed { return null; }

                public function incrementHashField(string $key, string $field, int|float $value): void
                {
                    if (is_float($value)) {
                        $this->pipe->hincrbyfloat($key, $field, $value);
                    } else {
                        $this->pipe->hincrby($key, $field, $value);
                    }
                }

                public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
                {
                    $this->pipe->zadd($key, $membersWithScores);
                    if ($ttl !== null) {
                        $this->pipe->expire($key, $ttl);
                    }
                }

                public function getSortedSetByRank(string $key, int $start, int $stop): array { return []; }
                public function getSortedSetByScore(string $key, string $min, string $max): array { return []; }
                public function countSortedSetByScore(string $key, string $min, string $max): int { return 0; }

                public function removeSortedSetByRank(string $key, int $start, int $stop): int
                {
                    return (int) $this->pipe->zremrangebyrank($key, $start, $stop);
                }

                public function removeFromSortedSet(string $key, string $member): void
                {
                    $this->pipe->zrem($key, $member);
                }

                public function addToSet(string $key, array $members): void
                {
                    if (! empty($members)) {
                        $this->pipe->sadd($key, $members);
                    }
                }

                public function getSetMembers(string $key): array { return []; }

                public function removeFromSet(string $key, array $members): void
                {
                    if (! empty($members)) {
                        $this->pipe->srem($key, $members);
                    }
                }

                public function set(string $key, mixed $value, ?int $ttl = null): void
                {
                    if ($ttl !== null) {
                        $this->pipe->setex($key, $ttl, $value);
                    } else {
                        $this->pipe->set($key, $value);
                    }
                }

                public function get(string $key): mixed { return null; }

                public function delete(array|string $keys): int
                {
                    if (is_string($keys)) {
                        $keys = [$keys];
                    }
                    if (! empty($keys)) {
                        $this->pipe->del(...$keys);
                    }

                    return 0;
                }

                public function exists(string $key): bool { return false; }

                public function expire(string $key, int $seconds): bool
                {
                    $this->pipe->expire($key, $seconds);

                    return true;
                }

                public function scanKeys(string $pattern): array { return []; }
                public function pipeline(callable $callback): void {}
            };

            $callback($wrapper);
        });
    }
}
