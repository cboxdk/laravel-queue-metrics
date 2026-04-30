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
 * Stale entries (from jobs that never completed, e.g. killed workers)
 * are evicted on every store() call after MAX_AGE_SECONDS.
 *
 * @internal
 */
final class JobCpuSnapshotCache
{
    private const MAX_AGE_SECONDS = 600;

    /** @var array<string, array{cpu_time_ms: float, stored_at: float}> */
    private static array $snapshots = [];

    /**
     * Get the cached CPU time baseline for a job, or null if not tracked.
     */
    public static function get(string $jobId): ?float
    {
        return isset(self::$snapshots[$jobId])
            ? self::$snapshots[$jobId]['cpu_time_ms']
            : null;
    }

    /**
     * Store the cumulative CPU time (ms) at job start and evict stale entries.
     */
    public static function store(string $jobId, float $cpuTimeMs): void
    {
        $now = microtime(true);

        self::$snapshots[$jobId] = [
            'cpu_time_ms' => $cpuTimeMs,
            'stored_at' => $now,
        ];

        $cutoff = $now - self::MAX_AGE_SECONDS;

        foreach (self::$snapshots as $id => $snapshot) {
            if ($snapshot['stored_at'] < $cutoff) {
                unset(self::$snapshots[$id]);
            }
        }
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
