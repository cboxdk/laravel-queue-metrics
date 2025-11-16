<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Storage\Contracts;

/**
 * Base contract for storage drivers.
 */
interface StorageDriver
{
    /**
     * Store a hash set of key-value pairs.
     *
     * @param array<string, mixed> $data
     */
    public function setHash(string $key, array $data, ?int $ttl = null): void;

    /**
     * Get all values from a hash.
     *
     * @return array<string, string>
     */
    public function getHash(string $key): array;

    /**
     * Get a specific field from a hash.
     */
    public function getHashField(string $key, string $field): mixed;

    /**
     * Increment a hash field by value.
     */
    public function incrementHashField(string $key, string $field, int|float $value): void;

    /**
     * Add members to a sorted set with scores.
     *
     * @param array<string|float, int|float> $membersWithScores
     */
    public function addToSortedSet(string $key, array $membersWithScores, ?int $ttl = null): void;

    /**
     * Get members from sorted set by rank.
     *
     * @return array<string>
     */
    public function getSortedSetByRank(string $key, int $start, int $stop): array;

    /**
     * Get members from sorted set by score range.
     *
     * @return array<string>
     */
    public function getSortedSetByScore(string $key, string $min, string $max): array;

    /**
     * Count members in sorted set by score range.
     */
    public function countSortedSetByScore(string $key, string $min, string $max): int;

    /**
     * Remove members from sorted set by rank range.
     */
    public function removeSortedSetByRank(string $key, int $start, int $stop): int;

    /**
     * Add members to a set.
     *
     * @param array<string> $members
     */
    public function addToSet(string $key, array $members): void;

    /**
     * Get all members from a set.
     *
     * @return array<string>
     */
    public function getSetMembers(string $key): array;

    /**
     * Remove members from a set.
     *
     * @param array<string> $members
     */
    public function removeFromSet(string $key, array $members): void;

    /**
     * Remove a member from a sorted set.
     */
    public function removeFromSortedSet(string $key, string $member): void;

    /**
     * Set a simple key-value pair.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void;

    /**
     * Get a simple value by key.
     */
    public function get(string $key): mixed;

    /**
     * Delete one or more keys.
     *
     * @param array<string>|string $keys
     */
    public function delete(array|string $keys): int;

    /**
     * Check if a key exists.
     */
    public function exists(string $key): bool;

    /**
     * Set TTL on a key.
     */
    public function expire(string $key, int $seconds): bool;

    /**
     * Scan for keys matching a pattern.
     *
     * @return array<string>
     */
    public function scanKeys(string $pattern): array;

    /**
     * Execute multiple commands in a pipeline/transaction.
     *
     * @param callable(mixed): void $callback
     */
    public function pipeline(callable $callback): void;
}
