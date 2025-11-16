<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Storage;

use Illuminate\Redis\Connections\Connection;
use PHPeek\LaravelQueueMetrics\Storage\Contracts\StorageDriver;

/**
 * Redis implementation of storage driver.
 */
final readonly class RedisStorageDriver implements StorageDriver
{
    public function __construct(
        private Connection $redis,
    ) {}

    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $this->redis->hmset($key, $data);

        if ($ttl !== null) {
            $this->redis->expire($key, $ttl);
        }
    }

    public function getHash(string $key): array
    {
        /** @var array<string, string> */
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
        /** @var array<string> */
        return $this->redis->zrange($key, $start, $stop);
    }

    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        /** @var array<string> */
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

    public function addToSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->sadd($key, $members);
        }
    }

    public function getSetMembers(string $key): array
    {
        /** @var array<string> */
        return $this->redis->smembers($key);
    }

    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->redis->srem($key, $members);
        }
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->redis->zrem($key, $member);
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
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} */
            $result = $this->redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
            [$cursor, $found] = $result;
            $keys = array_merge($keys, $found);
        } while ($cursor !== '0');

        return $keys;
    }

    public function pipeline(callable $callback): void
    {
        $this->redis->pipeline($callback);
    }
}
