<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-process tracker for debounced jobs.
 *
 * Allows JobDebouncedListener to mark a job ID, and JobProcessedListener
 * to check and consume that mark. The mark is auto-consumed on read
 * to prevent memory leaks in long-running workers.
 *
 * Stale entries (from jobs that were marked but never consumed) are
 * evicted after MAX_AGE_SECONDS on every mark() call.
 *
 * @internal
 */
final class DebouncedJobTracker
{
    private const MAX_AGE_SECONDS = 600;

    /** @var array<string, float> Job ID => timestamp when marked */
    private static array $debouncedJobIds = [];

    public static function mark(string $jobId): void
    {
        $now = microtime(true);
        self::$debouncedJobIds[$jobId] = $now;

        $cutoff = $now - self::MAX_AGE_SECONDS;
        foreach (self::$debouncedJobIds as $id => $timestamp) {
            if ($timestamp < $cutoff) {
                unset(self::$debouncedJobIds[$id]);
            }
        }
    }

    /**
     * Check if a job was debounced and consume the mark.
     */
    public static function wasDebounced(string $jobId): bool
    {
        if (isset(self::$debouncedJobIds[$jobId])) {
            unset(self::$debouncedJobIds[$jobId]);

            return true;
        }

        return false;
    }

    public static function flush(): void
    {
        self::$debouncedJobIds = [];
    }
}
