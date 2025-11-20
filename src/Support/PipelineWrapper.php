<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

/**
 * Wrapper around Laravel Redis pipeline for batch operations.
 *
 * @phpstan-type PipelineType \Redis|\Illuminate\Redis\Connections\PhpRedisConnection
 */
final class PipelineWrapper
{
    /**
     * @param  PipelineType  $pipe
     */
    public function __construct(
        private mixed $pipe,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $this->pipe->hmset($key, $data);
        if ($ttl !== null) {
            $this->pipe->expire($key, $ttl);
        }
    }

    public function getHash(string $key): mixed
    {
        return $this->pipe->hgetall($key);
    }

    public function getHashField(string $key, string $field): mixed
    {
        return $this->pipe->hget($key, $field);
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        if (is_float($value)) {
            $this->pipe->hincrbyfloat($key, $field, $value);
        } else {
            $this->pipe->hincrby($key, $field, $value);
        }
    }

    /**
     * @param  array<string, float|int>  $membersWithScores
     */
    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        // PhpRedis zadd expects: zadd(key, score1, member1, score2, member2, ...)
        // We receive: ['member1' => score1, 'member2' => score2, ...]
        // Build args array starting with key, then alternating scores and members
        foreach ($membersWithScores as $member => $score) {
            $this->pipe->zadd($key, $score, $member);
        }

        if ($ttl !== null) {
            $this->pipe->expire($key, $ttl);
        }
    }

    public function getSortedSetByRank(string $key, int $start, int $stop): mixed
    {
        return $this->pipe->zrange($key, $start, $stop);
    }

    public function getSortedSetByScore(string $key, string $min, string $max): mixed
    {
        return $this->pipe->zrangebyscore($key, $min, $max);
    }

    public function countSortedSetByScore(string $key, string $min, string $max): mixed
    {
        return $this->pipe->zcount($key, $min, $max);
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): mixed
    {
        return $this->pipe->zremrangebyrank($key, $start, $stop);
    }

    public function removeSortedSetByScore(string $key, string $min, string $max): mixed
    {
        return $this->pipe->zremrangebyscore($key, $min, $max);
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->pipe->zrem($key, $member);
    }

    /**
     * @param  array<int, string>  $members
     */
    public function addToSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->pipe->sadd($key, ...$members);
        }
    }

    public function getSetMembers(string $key): mixed
    {
        return $this->pipe->smembers($key);
    }

    /**
     * @param  array<int, string>  $members
     */
    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->pipe->srem($key, ...$members);
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

    public function get(string $key): mixed
    {
        return $this->pipe->get($key);
    }

    /**
     * @param  array<int, string>|string  $keys
     */
    public function delete(array|string $keys): void
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }
        if (! empty($keys)) {
            $this->pipe->del(...$keys);
        }
    }

    public function exists(string $key): mixed
    {
        return $this->pipe->exists($key);
    }

    public function expire(string $key, int $seconds): void
    {
        $this->pipe->expire($key, $seconds);
    }
}
