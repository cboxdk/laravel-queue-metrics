<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory throttle cache for database heartbeat writes.
 *
 * Prevents excessive database writes when the worker state has not changed.
 * Extracted as a standalone class because PHP readonly classes cannot contain
 * static properties.
 *
 * @internal
 */
final class HeartbeatThrottleCache
{
    private const THROTTLE_SECONDS = 10;

    /** @var array<string, int> Last heartbeat write timestamp per worker ID */
    private static array $lastWrite = [];

    /** @var array<string, string> Last known state value per worker ID */
    private static array $lastState = [];

    /**
     * Determine whether the heartbeat write should be skipped.
     */
    public static function shouldSkip(string $workerId, string $stateValue, int $currentTimestamp): bool
    {
        $lastWrite = self::$lastWrite[$workerId] ?? 0;
        $lastKnownState = self::$lastState[$workerId] ?? null;
        $timeSinceWrite = $currentTimestamp - $lastWrite;

        return $timeSinceWrite < self::THROTTLE_SECONDS && $lastKnownState === $stateValue;
    }

    /**
     * Record that a heartbeat was written for the given worker.
     */
    public static function record(string $workerId, string $stateValue, int $currentTimestamp): void
    {
        self::$lastWrite[$workerId] = $currentTimestamp;
        self::$lastState[$workerId] = $stateValue;
    }

    /**
     * Reset the in-memory throttle cache.
     *
     * Intended for use in tests to ensure a clean state between test cases.
     */
    public static function reset(): void
    {
        self::$lastWrite = [];
        self::$lastState = [];
    }
}
