<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Simple wrapper around Laravel's Redis for queue metrics storage.
 * Uses Laravel's Redis connection directly instead of abstraction layer.
 */
final class RedisMetricsStore
{
    private ?Connection $redis = null;

    private ?string $prefix = null;

    /**
     * Get Redis connection (lazy loaded).
     */
    private function getRedis(): Connection
    {
        if ($this->redis === null) {
            /** @var string $connection */
            $connection = config('queue-metrics.storage.connection', 'default');
            $this->redis = Redis::connection($connection);
        }

        return $this->redis;
    }

    /**
     * Get prefix (lazy loaded).
     */
    private function getPrefix(): string
    {
        if ($this->prefix === null) {
            /** @var string $prefix */
            $prefix = config('queue-metrics.storage.prefix', 'queue_metrics');
            $this->prefix = $prefix;
        }

        return $this->prefix;
    }

    /**
     * Build a Redis key from segments.
     */
    public function key(string ...$segments): string
    {
        return $this->getPrefix().':'.implode(':', $segments);
    }

    /**
     * Get TTL for a given key type.
     */
    public function getTtl(string $type): int
    {
        /** @var int */
        return config("queue-metrics.storage.ttl.{$type}", 3600);
    }

    /**
     * Get the underlying Redis connection.
     */
    public function connection(): Connection
    {
        return $this->getRedis();
    }

    /**
     * Return self as driver (for StorageManager compatibility).
     */
    public function driver(): self
    {
        return $this;
    }

    // StorageDriver interface methods

    /**
     * @param  array<string, mixed>  $data
     */
    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $redis = $this->getRedis();
        $redis->hmset($key, $data);

        if ($ttl !== null) {
            $redis->expire($key, $ttl);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getHash(string $key): array
    {
        $result = $this->getRedis()->hgetall($key);

        return is_array($result) ? $result : [];
    }

    public function getHashField(string $key, string $field): mixed
    {
        return $this->getRedis()->hget($key, $field);
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        $redis = $this->getRedis();
        if (is_float($value)) {
            $redis->hincrbyfloat($key, $field, $value);
        } else {
            $redis->hincrby($key, $field, $value);
        }
    }

    /**
     * @param  array<string, float|int>  $membersWithScores
     */
    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        $this->getRedis()->zadd($key, $membersWithScores);

        if ($ttl !== null) {
            $this->getRedis()->expire($key, $ttl);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByRank(string $key, int $start, int $stop): array
    {
        $result = $this->getRedis()->zrange($key, $start, $stop);

        return is_array($result) ? $result : [];
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        $result = $this->getRedis()->zrangebyscore($key, $min, $max);

        return is_array($result) ? $result : [];
    }

    public function countSortedSetByScore(string $key, string $min, string $max): int
    {
        return (int) $this->getRedis()->zcount($key, $min, $max);
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): int
    {
        return (int) $this->getRedis()->zremrangebyrank($key, $start, $stop);
    }

    public function removeSortedSetByScore(string $key, string $min, string $max): int
    {
        return (int) $this->getRedis()->zremrangebyscore($key, $min, $max);
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->getRedis()->zrem($key, $member);
    }

    /**
     * @param  array<int, string>  $members
     */
    public function addToSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->getRedis()->sadd($key, ...$members);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSetMembers(string $key): array
    {
        $result = $this->getRedis()->smembers($key);

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<int, string>  $members
     */
    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            $this->getRedis()->srem($key, ...$members);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            $this->getRedis()->setex($key, $ttl, $value);
        } else {
            $this->getRedis()->set($key, $value);
        }
    }

    public function get(string $key): mixed
    {
        return $this->getRedis()->get($key);
    }

    /**
     * @param  array<int, string>|string  $keys
     */
    public function delete(array|string $keys): int
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }

        if (empty($keys)) {
            return 0;
        }

        return (int) $this->getRedis()->del(...$keys);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->getRedis()->exists($key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->getRedis()->expire($key, $seconds);
    }

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern): array
    {
        // Get the underlying PhpRedis client - it includes Redis connection prefix
        /** @var \Redis $client */
        $client = $this->getRedis()->client();

        // Get Laravel's Redis connection prefix (e.g., 'laravel_database_')
        $connectionPrefix = $this->getRedis()->_prefix('');

        // Combine connection prefix with our pattern
        $fullPattern = $connectionPrefix.$pattern;

        $keys = [];
        $cursor = null;

        do {
            // PhpRedis scan() signature: scan(&$cursor, $pattern, $count)
            /** @var array<int, string>|false $result */
            $result = $client->scan($cursor, $fullPattern, 1000);

            if ($result === false) {
                break;
            }

            // @phpstan-ignore-next-line - PHPDoc type assertion ensures is_array check is valid
            if (is_array($result) && ! empty($result)) {
                $keys = array_merge($keys, $result);
            }
            // @phpstan-ignore-next-line - Complex scan cursor state management
        } while ($cursor !== 0 && $cursor !== null);

        /** @var array<int, string> */
        return $keys;
    }

    public function pipeline(callable $callback): void
    {
        // @phpstan-ignore-next-line - PhpRedis pipeline accepts no parameters in newer versions
        $this->getRedis()->pipeline(function ($pipe) use ($callback) {
            $wrapper = new PipelineWrapper($pipe);
            $callback($wrapper);
        });
    }

    /**
     * Execute commands in a Redis transaction (MULTI/EXEC).
     * Ensures all commands are executed atomically.
     *
     * @param  callable(PipelineWrapper): void  $callback
     * @return array<int, mixed> Results of executed commands
     */
    public function transaction(callable $callback): array
    {
        $redis = $this->getRedis();

        // Laravel Redis uses multi() and exec() for transactions
        $redis->multi();

        try {
            // @phpstan-ignore-next-line - Laravel Connection type is compatible with PipelineWrapper
            $wrapper = new PipelineWrapper($redis);
            $callback($wrapper);

            $results = $redis->exec();

            return is_array($results) ? $results : [];
        } catch (\Throwable $e) {
            $redis->discard();
            throw $e;
        }
    }

    /**
     * Execute Lua script atomically on Redis.
     *
     * @param  mixed  ...$args
     */
    public function eval(string $script, int $numKeys, ...$args): mixed
    {
        // @phpstan-ignore-next-line - Redis eval accepts variadic args but PHPStan expects array
        return $this->getRedis()->eval($script, $numKeys, ...$args);
    }

    /**
     * Execute generic Redis command.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function command(string $method, array $parameters = []): mixed
    {
        return $this->getRedis()->command($method, $parameters);
    }
}
