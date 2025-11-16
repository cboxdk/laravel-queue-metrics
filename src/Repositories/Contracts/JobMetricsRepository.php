<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories\Contracts;

use Carbon\Carbon;

/**
 * Repository contract for job metrics storage and retrieval.
 */
interface JobMetricsRepository
{
    /**
     * Record a job start event.
     */
    public function recordStart(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void;

    /**
     * Record a job completion event.
     */
    public function recordCompletion(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs,
        Carbon $completedAt,
    ): void;

    /**
     * Record a job failure event.
     */
    public function recordFailure(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
    ): void;

    /**
     * Get raw metrics for a specific job.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(
        string $jobClass,
        string $connection,
        string $queue,
    ): array;

    /**
     * Get duration samples for percentile calculations.
     *
     * @return array<int, float>
     */
    public function getDurationSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array;

    /**
     * Get memory samples for percentile calculations.
     *
     * @return array<int, float>
     */
    public function getMemorySamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array;

    /**
     * Get CPU time samples for percentile calculations.
     *
     * @return array<int, float>
     */
    public function getCpuTimeSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array;

    /**
     * Get throughput for a specific time window.
     */
    public function getThroughput(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): int;

    /**
     * Record when a job is queued for time-to-start tracking.
     */
    public function recordQueuedAt(
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $queuedAt,
    ): void;

    /**
     * Record a retry request for tracking retry patterns.
     */
    public function recordRetryRequested(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $retryRequestedAt,
        int $attemptNumber,
    ): void;

    /**
     * Record a job timeout event.
     */
    public function recordTimeout(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $timedOutAt,
    ): void;

    /**
     * Record an exception occurrence during job execution.
     */
    public function recordException(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exceptionClass,
        string $exceptionMessage,
        Carbon $occurredAt,
    ): void;

    /**
     * Clean up old metrics data.
     */
    public function cleanup(int $olderThanSeconds): int;
}
