<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory cache of CPU time snapshots at job start for per-job CPU delta calculation.
 *
 * Stores the worker's cumulative CPU time (user + system) when a job begins processing.
 * At job completion, the listener reads this value and computes
 * (endCpuTimeMs - startCpuTimeMs) for the actual CPU time consumed by the job.
 *
 * Entries are consumed via forget() when the job completes or fails.
 * No TTL needed — job lifecycle is bounded within a single process.
 *
 * @internal
 */
final class JobCpuSnapshotCache
{
    /** @var array<string, float> */
    private static array $snapshots = [];

    /**
     * Get the cached CPU time baseline for a job, or null if not tracked.
     */
    public static function get(string $jobId): ?float
    {
        return self::$snapshots[$jobId] ?? null;
    }

    /**
     * Store the cumulative CPU time (ms) at job start.
     */
    public static function store(string $jobId, float $cpuTimeMs): void
    {
        self::$snapshots[$jobId] = $cpuTimeMs;
    }

    /**
     * Remove the entry for a completed/failed job.
     */
    public static function forget(string $jobId): void
    {
        unset(self::$snapshots[$jobId]);
    }

    /**
     * Clear all entries. For use in tests only.
     */
    public static function reset(): void
    {
        self::$snapshots = [];
    }
}
