<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Models\MetricsKey;
use Cbox\LaravelQueueMetrics\Models\MetricsSet;
use Cbox\LaravelQueueMetrics\Models\MetricsSortedSet;
use Illuminate\Support\Facades\DB;

/**
 * Database-backed metrics store using Eloquent models.
 * Provides the same public interface as RedisMetricsStore for queue metrics storage.
 */
final class DatabaseMetricsStore
{
    /**
     * Build a key from segments.
     * The database driver does not prepend a prefix to keys because the table
     * names (e.g. queue_metrics_keys) already act as the namespace.
     */
    public function key(string ...$segments): string
    {
        return implode(':', $segments);
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
     * Return self as driver (for StorageManager compatibility).
     */
    public function driver(): self
    {
        return $this;
    }

    /**
     * Return self as connection (for StorageManager compatibility).
     */
    public function connection(): self
    {
        return $this;
    }

    // --- String operations ---

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        MetricsKey::updateOrCreate(
            ['key' => $key],
            [
                'value' => is_string($value) ? $value : json_encode($value),
                'expires_at' => $ttl !== null ? now()->addSeconds($ttl) : null,
                'updated_at' => now(),
            ]
        );
    }

    public function get(string $key): mixed
    {
        $record = MetricsKey::notExpired()->find($key);

        return $record?->value;
    }

    public function exists(string $key): bool
    {
        return MetricsKey::notExpired()->where('key', $key)->exists();
    }

    /**
     * @param  array<int, string>|string  $keys
     */
    public function delete(array|string $keys): int
    {
        $keys = is_array($keys) ? $keys : [$keys];

        if (empty($keys)) {
            return 0;
        }

        /** @var int $a */
        $a = MetricsKey::whereIn('key', $keys)->delete();
        /** @var int $b */
        $b = MetricsHash::whereIn('key', $keys)->delete();
        /** @var int $c */
        $c = MetricsSet::whereIn('key', $keys)->delete();
        /** @var int $d */
        $d = MetricsSortedSet::whereIn('key', $keys)->delete();
        $count = $a + $b + $c + $d;

        return $count;
    }

    public function expire(string $key, int $seconds): bool
    {
        $expiresAt = now()->addSeconds($seconds);

        $affected = 0;
        $affected += MetricsKey::where('key', $key)->update(['expires_at' => $expiresAt]);
        $affected += MetricsHash::where('key', $key)->update(['expires_at' => $expiresAt]);
        $affected += MetricsSortedSet::where('key', $key)->update(['expires_at' => $expiresAt]);

        return $affected > 0;
    }

    // --- Hash operations ---

    /**
     * @param  array<string, mixed>  $data
     */
    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        DB::transaction(function () use ($key, $data, $ttl) {
            $existing = MetricsHash::lockForUpdate()->find($key);
            $merged = $existing ? array_merge($existing->data, $data) : $data;

            MetricsHash::updateOrCreate(
                ['key' => $key],
                [
                    'data' => $merged,
                    'expires_at' => $ttl !== null ? now()->addSeconds($ttl) : ($existing?->expires_at),
                    'updated_at' => now(),
                ]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getHash(string $key): array
    {
        /** @var MetricsHash|null $record */
        $record = MetricsHash::notExpired()->find($key);

        return $record !== null ? $record->data : [];
    }

    public function getHashField(string $key, string $field): mixed
    {
        $data = $this->getHash($key);

        return $data[$field] ?? null;
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        DB::transaction(function () use ($key, $field, $value) {
            /** @var MetricsHash|null $record */
            $record = MetricsHash::lockForUpdate()->find($key);

            $data = $record !== null ? $record->data : [];
            $current = is_numeric($data[$field] ?? null) ? $data[$field] : 0;
            $data[$field] = is_float($value) ? (float) $current + $value : (int) $current + $value;

            MetricsHash::updateOrCreate(
                ['key' => $key],
                ['data' => $data, 'updated_at' => now()]
            );
        });
    }

    // --- Sorted set operations ---

    /**
     * @param  array<string, float|int>  $membersWithScores
     */
    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        $expiresAt = $ttl !== null ? now()->addSeconds($ttl) : null;
        $now = now();

        $rows = [];
        foreach ($membersWithScores as $member => $score) {
            $rows[] = [
                'key' => $key,
                'member' => (string) $member,
                'score' => $score,
                'expires_at' => $expiresAt,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            MetricsSortedSet::upsert($rows, ['key', 'member'], ['score', 'expires_at', 'updated_at']);
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        $query = MetricsSortedSet::notExpired()
            ->where('key', $key)
            ->orderBy('score');

        if ($min !== '-inf') {
            $query->where('score', '>=', (float) $min);
        }

        if ($max !== '+inf' && $max !== 'inf') {
            $query->where('score', '<=', (float) $max);
        }

        /** @var array<int, string> */
        return $query->pluck('member')->all();
    }

    /**
     * @return array<int, string>
     */
    public function getSortedSetByRank(string $key, int $start, int $stop): array
    {
        $query = MetricsSortedSet::notExpired()->where('key', $key)->orderBy('score');

        if ($start < 0 || $stop < 0) {
            $total = (clone $query)->count();
            if ($start < 0) {
                $start = max(0, $total + $start);
            }
            if ($stop < 0) {
                $stop = $total + $stop;
            }
        }

        if ($stop < $start) {
            return [];
        }

        $limit = $stop - $start + 1;

        /** @var array<int, string> */
        return $query->offset($start)->limit($limit)->pluck('member')->all();
    }

    public function countSortedSetByScore(string $key, string $min, string $max): int
    {
        $query = MetricsSortedSet::notExpired()->where('key', $key);

        if ($min !== '-inf') {
            $query->where('score', '>=', (float) $min);
        }

        if ($max !== '+inf' && $max !== 'inf') {
            $query->where('score', '<=', (float) $max);
        }

        return $query->count();
    }

    public function removeSortedSetByScore(string $key, string $min, string $max): int
    {
        $query = MetricsSortedSet::where('key', $key);

        if ($min !== '-inf') {
            $query->where('score', '>=', (float) $min);
        }

        if ($max !== '+inf' && $max !== 'inf') {
            $query->where('score', '<=', (float) $max);
        }

        /** @var int */
        return $query->delete();
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): int
    {
        $query = MetricsSortedSet::where('key', $key)->orderBy('score');

        if ($start < 0 || $stop < 0) {
            $total = (clone $query)->count();
            if ($start < 0) {
                $start = max(0, $total + $start);
            }
            if ($stop < 0) {
                $stop = $total + $stop;
            }
        }

        if ($stop < $start) {
            return 0;
        }

        $members = (clone $query)
            ->offset($start)
            ->limit($stop - $start + 1)
            ->pluck('member')
            ->all();

        if (empty($members)) {
            return 0;
        }

        /** @var int */
        return MetricsSortedSet::where('key', $key)
            ->whereIn('member', $members)
            ->delete();
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        MetricsSortedSet::where('key', $key)->where('member', $member)->delete();
    }

    // --- Set operations ---

    /**
     * @param  array<int, string>  $members
     */
    public function addToSet(string $key, array $members): void
    {
        foreach ($members as $member) {
            MetricsSet::firstOrCreate(
                ['key' => $key, 'member' => (string) $member],
                ['created_at' => now()]
            );
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSetMembers(string $key): array
    {
        /** @var array<int, string> */
        return MetricsSet::where('key', $key)->pluck('member')->all();
    }

    /**
     * @param  array<int, string>  $members
     */
    public function removeFromSet(string $key, array $members): void
    {
        if (! empty($members)) {
            MetricsSet::where('key', $key)->whereIn('member', $members)->delete();
        }
    }

    // --- Key scanning ---

    /**
     * @return array<int, string>
     */
    public function scanKeys(string $pattern): array
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
        $sqlPattern = str_replace(['*', '?'], ['%', '_'], $escaped);

        /** @var array<int, string> */
        return MetricsKey::notExpired()
            ->where('key', 'like', $sqlPattern)
            ->pluck('key')
            ->all();
    }

    // --- Transaction/Pipeline ---

    public function pipeline(callable $callback): void
    {
        DB::transaction(function () use ($callback) {
            $callback($this);
        });
    }

    /**
     * Execute operations in a database transaction.
     *
     * @param  callable(self): mixed  $callback
     * @return array<int, mixed>
     */
    public function transaction(callable $callback): array
    {
        $result = [];
        DB::transaction(function () use ($callback, &$result) {
            $callbackResult = $callback($this);
            $result = is_array($callbackResult) ? $callbackResult : [];
        });

        return $result;
    }

    // --- Lua script replacement (no-op for database) ---

    /**
     * No-op for database driver. Lua scripts are Redis-specific.
     *
     * @param  mixed  ...$args
     */
    public function eval(string $script, int $numKeys, ...$args): mixed
    {
        return null;
    }

    /**
     * No-op for database driver. Raw Redis commands are not applicable.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function command(string $method, array $parameters = []): mixed
    {
        return null;
    }
}
