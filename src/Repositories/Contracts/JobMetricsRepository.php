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
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void;

    /**
     * Record a job completion event.
     */
    public function recordCompletion(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs,
        Carbon $completedAt,
        ?string $hostname = null,
    ): void;

    /**
     * Record a job failure event.
     */
    public function recordFailure(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
        ?string $hostname = null,
    ): void;

    /**
     * Get hostname-scoped job metrics for a specific server.
     *
     * @return array<string, array{total_processed: int, total_failed: int, total_duration_ms: float, failure_rate: float, avg_duration_ms: float}>
     */
    public function getHostnameJobMetrics(string $hostname): array;

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
     * Get average duration for jobs completed within a specific time window.
     *
     * @return float Average duration in milliseconds, 0.0 if no jobs in window
     */
    public function getAverageDurationInWindow(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): float;

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
        string|int $jobId,
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
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $timedOutAt,
    ): void;

    /**
     * Record an exception occurrence during job execution.
     */
    public function recordException(
        string|int $jobId,
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

    /**
     * List all discovered jobs (push-based discovery).
     *
     * @return array<int, array{connection: string, queue: string, jobClass: string}>
     */
    public function listJobs(): array;

    /**
     * Register job in discovery set (push-based tracking).
     */
    public function markJobDiscovered(string $connection, string $queue, string $jobClass): void;
}
