<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory cache of previous CPU snapshots for delta-based CPU usage calculation.
 *
 * Stores the cumulative CPU time and wall-clock timestamp from the last heartbeat
 * per worker, enabling true CPU usage percentage as (deltaCpu / deltaWall) * 100.
 *
 * Extracted as a standalone class because PHP readonly classes cannot contain
 * static properties.
 *
 * @internal
 */
final class CpuSnapshotCache
{
    /** @var array<string, array{cpu_time_ms: float, wall_time: float}> */
    private static array $snapshots = [];

    /**
     * Get the previous snapshot for a worker, or null if this is the first heartbeat.
     *
     * @return array{cpu_time_ms: float, wall_time: float}|null
     */
    public static function get(string $workerId): ?array
    {
        return self::$snapshots[$workerId] ?? null;
    }

    /**
     * Store a CPU snapshot for the given worker.
     */
    public static function store(string $workerId, float $cpuTimeMs, float $wallTime): void
    {
        self::$snapshots[$workerId] = [
            'cpu_time_ms' => $cpuTimeMs,
            'wall_time' => $wallTime,
        ];
    }

    /**
     * Reset the in-memory snapshot cache.
     *
     * Intended for use in tests to ensure a clean state between test cases.
     */
    public static function reset(): void
    {
        self::$snapshots = [];
    }
}
