<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * In-memory record of when each job started processing in this worker.
 *
 * Job duration is measured from this time, not from the payload's `pushedAt`,
 * which is the dispatch time and so would add the job's wait in the queue.
 *
 * Entries are consumed via forget() when the job completes, fails or is
 * debounced. Entries left behind (a job released back to the queue after an
 * exception) are bounded by count rather than age, so a long-running job never
 * loses its start time.
 *
 * @internal
 */
final class JobStartTimeCache
{
    private const MAX_ENTRIES = 100;

    /** @var array<string, float> */
    private static array $startedAt = [];

    public static function get(string $jobId): ?float
    {
        return self::$startedAt[$jobId] ?? null;
    }

    public static function store(string $jobId, float $startedAt): void
    {
        unset(self::$startedAt[$jobId]);

        self::$startedAt[$jobId] = $startedAt;

        if (count(self::$startedAt) > self::MAX_ENTRIES) {
            self::$startedAt = array_slice(self::$startedAt, -self::MAX_ENTRIES, preserve_keys: true);
        }
    }

    public static function forget(string $jobId): void
    {
        unset(self::$startedAt[$jobId]);
    }

    /**
     * Clear all entries. For use in tests only.
     */
    public static function reset(): void
    {
        self::$startedAt = [];
    }
}
