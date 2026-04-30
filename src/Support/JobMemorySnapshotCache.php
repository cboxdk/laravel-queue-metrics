<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory cache of memory RSS snapshots at job start for per-job memory delta calculation.
 *
 * Stores the worker's RSS (in MB) when a job begins processing.
 * At job completion, the listener reads this value and computes
 * (peakMemoryMb - startMemoryMb) for the incremental memory allocated by the job.
 *
 * Entries are consumed via forget() when the job completes or fails.
 * Stale entries (from jobs that never completed, e.g. killed workers)
 * are evicted on every store() call after MAX_AGE_SECONDS.
 *
 * @internal
 */
final class JobMemorySnapshotCache
{
    private const MAX_AGE_SECONDS = 600;

    /** @var array<string, array{memory_rss_mb: float, stored_at: float}> */
    private static array $snapshots = [];

    /**
     * Get the cached memory RSS baseline for a job, or null if not tracked.
     */
    public static function get(string $jobId): ?float
    {
        return isset(self::$snapshots[$jobId])
            ? self::$snapshots[$jobId]['memory_rss_mb']
            : null;
    }

    /**
     * Store the RSS memory (MB) at job start and evict stale entries.
     */
    public static function store(string $jobId, float $memoryRssMb): void
    {
        $now = microtime(true);

        self::$snapshots[$jobId] = [
            'memory_rss_mb' => $memoryRssMb,
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
