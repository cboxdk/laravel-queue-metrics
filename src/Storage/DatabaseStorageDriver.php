<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Storage;

use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPeek\LaravelQueueMetrics\Storage\Contracts\StorageDriver;

/**
 * Database implementation of storage driver.
 * Uses JSON columns to emulate Redis hash and sorted set operations.
 */
final readonly class DatabaseStorageDriver implements StorageDriver
{
    public function __construct(
        private Connection $db,
        private string $tablePrefix = 'queue_metrics_',
    ) {}

    public function setHash(string $key, array $data, ?int $ttl = null): void
    {
        $expiresAt = $ttl !== null ? Carbon::now()->addSeconds($ttl) : null;

        $this->db->table($this->tablePrefix . 'hashes')->updateOrInsert(
            ['key' => $key],
            [
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
                'expires_at' => $expiresAt,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    public function getHash(string $key): array
    {
        $result = $this->db->table($this->tablePrefix . 'hashes')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->first();

        if ($result === null) {
            return [];
        }

        /** @var array<string, string> */
        return json_decode($result->data, true) ?: [];
    }

    public function getHashField(string $key, string $field): mixed
    {
        $hash = $this->getHash($key);

        return $hash[$field] ?? null;
    }

    public function incrementHashField(string $key, string $field, int|float $value): void
    {
        $hash = $this->getHash($key);
        $currentValue = isset($hash[$field]) ? (is_float($value) ? (float) $hash[$field] : (int) $hash[$field]) : 0;
        $hash[$field] = (string) ($currentValue + $value);

        $this->setHash($key, $hash);
    }

    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void
    {
        $expiresAt = $ttl !== null ? Carbon::now()->addSeconds($ttl) : null;

        foreach ($membersWithScores as $member => $score) {
            $this->db->table($this->tablePrefix . 'sorted_sets')->updateOrInsert(
                ['key' => $key, 'member' => (string) $member],
                [
                    'score' => $score,
                    'expires_at' => $expiresAt,
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }

    public function getSortedSetByRank(string $key, int $start, int $stop): array
    {
        $query = $this->db->table($this->tablePrefix . 'sorted_sets')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->orderBy('score');

        if ($stop === -1) {
            $query->skip($start);
        } else {
            $limit = $stop - $start + 1;
            $query->skip($start)->take($limit);
        }

        return $query->pluck('member')->toArray();
    }

    public function getSortedSetByScore(string $key, string $min, string $max): array
    {
        $query = $this->db->table($this->tablePrefix . 'sorted_sets')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });

        if ($min !== '-inf') {
            $query->where('score', '>=', $min);
        }

        if ($max !== '+inf') {
            $query->where('score', '<=', $max);
        }

        return $query->orderBy('score')->pluck('member')->toArray();
    }

    public function countSortedSetByScore(string $key, string $min, string $max): int
    {
        $query = $this->db->table($this->tablePrefix . 'sorted_sets')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });

        if ($min !== '-inf') {
            $query->where('score', '>=', $min);
        }

        if ($max !== '+inf') {
            $query->where('score', '<=', $max);
        }

        return $query->count();
    }

    public function removeSortedSetByRank(string $key, int $start, int $stop): int
    {
        $members = $this->getSortedSetByRank($key, $start, $stop);

        if (empty($members)) {
            return 0;
        }

        return $this->db->table($this->tablePrefix . 'sorted_sets')
            ->where('key', $key)
            ->whereIn('member', $members)
            ->delete();
    }

    public function addToSet(string $key, array $members): void
    {
        foreach ($members as $member) {
            $this->db->table($this->tablePrefix . 'sets')->insertOrIgnore([
                'key' => $key,
                'member' => $member,
                'created_at' => Carbon::now(),
            ]);
        }
    }

    public function getSetMembers(string $key): array
    {
        return $this->db->table($this->tablePrefix . 'sets')
            ->where('key', $key)
            ->pluck('member')
            ->toArray();
    }

    public function removeFromSet(string $key, array $members): void
    {
        if (empty($members)) {
            return;
        }

        $this->db->table($this->tablePrefix . 'sets')
            ->where('key', $key)
            ->whereIn('member', $members)
            ->delete();
    }

    public function removeFromSortedSet(string $key, string $member): void
    {
        $this->db->table($this->tablePrefix . 'sorted_sets')
            ->where('key', $key)
            ->where('member', $member)
            ->delete();
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $expiresAt = $ttl !== null ? Carbon::now()->addSeconds($ttl) : null;

        $this->db->table($this->tablePrefix . 'keys')->updateOrInsert(
            ['key' => $key],
            [
                'value' => is_array($value) || is_object($value)
                    ? json_encode($value, JSON_THROW_ON_ERROR)
                    : (string) $value,
                'expires_at' => $expiresAt,
                'updated_at' => Carbon::now(),
            ]
        );
    }

    public function get(string $key): mixed
    {
        $result = $this->db->table($this->tablePrefix . 'keys')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->first();

        return $result?->value;
    }

    public function delete(array|string $keys): int
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }

        if (empty($keys)) {
            return 0;
        }

        $deleted = 0;

        // Delete from all tables
        $deleted += $this->db->table($this->tablePrefix . 'keys')->whereIn('key', $keys)->delete();
        $deleted += $this->db->table($this->tablePrefix . 'hashes')->whereIn('key', $keys)->delete();
        $deleted += $this->db->table($this->tablePrefix . 'sets')->whereIn('key', $keys)->delete();
        $deleted += $this->db->table($this->tablePrefix . 'sorted_sets')->whereIn('key', $keys)->delete();

        return $deleted;
    }

    public function exists(string $key): bool
    {
        return $this->db->table($this->tablePrefix . 'keys')
            ->where('key', $key)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })
            ->exists();
    }

    public function expire(string $key, int $seconds): bool
    {
        $expiresAt = Carbon::now()->addSeconds($seconds);

        $updated = 0;
        $updated += $this->db->table($this->tablePrefix . 'keys')
            ->where('key', $key)
            ->update(['expires_at' => $expiresAt]);

        $updated += $this->db->table($this->tablePrefix . 'hashes')
            ->where('key', $key)
            ->update(['expires_at' => $expiresAt]);

        return $updated > 0;
    }

    public function scanKeys(string $pattern): array
    {
        // Convert Redis glob pattern to SQL LIKE pattern
        $likePattern = str_replace(['*', '?'], ['%', '_'], $pattern);

        $keys = [];
        $keys = array_merge($keys, $this->db->table($this->tablePrefix . 'keys')
            ->where('key', 'like', $likePattern)
            ->pluck('key')
            ->toArray());

        $keys = array_merge($keys, $this->db->table($this->tablePrefix . 'hashes')
            ->where('key', 'like', $likePattern)
            ->pluck('key')
            ->toArray());

        return array_unique($keys);
    }

    public function pipeline(callable $callback): void
    {
        // Database doesn't have true pipelining, use transaction instead
        $this->db->transaction(function () use ($callback) {
            $callback($this);
        });
    }
}
