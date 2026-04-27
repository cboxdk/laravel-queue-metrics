<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-process tracker for debounced jobs.
 *
 * Allows JobDebouncedListener to mark a job ID, and JobProcessedListener
 * to check and consume that mark. The mark is auto-consumed on read
 * to prevent memory leaks in long-running workers.
 */
final class DebouncedJobTracker
{
    /** @var array<string, true> */
    private static array $debouncedJobIds = [];

    public static function mark(string $jobId): void
    {
        self::$debouncedJobIds[$jobId] = true;
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
