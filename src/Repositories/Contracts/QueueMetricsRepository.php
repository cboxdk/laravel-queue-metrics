<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories\Contracts;

/**
 * Repository contract for queue-level metrics storage and retrieval.
 */
interface QueueMetricsRepository
{
    /**
     * Get current queue state from Laravel queue driver.
     *
     * @return array{depth: int, pending: int, scheduled: int, reserved: int, oldest_job_age: int}
     */
    public function getQueueState(string $connection, string $queue): array;

    /**
     * Record queue metrics snapshot.
     *
     * @param  array<string, mixed>  $metrics
     */
    public function recordSnapshot(
        string $connection,
        string $queue,
        array $metrics,
    ): void;

    /**
     * Get latest metrics for a queue.
     *
     * @return array<string, mixed>
     */
    public function getLatestMetrics(string $connection, string $queue): array;

    /**
     * Get queue health status.
     *
     * @return array{status: string, score: float}
     */
    public function getHealthStatus(string $connection, string $queue): array;

    /**
     * List all discovered queues.
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    public function listQueues(): array;

    /**
     * Mark a queue as discovered.
     */
    public function markQueueDiscovered(string $connection, string $queue): void;

    /**
     * Clean up old queue snapshots.
     */
    public function cleanup(int $olderThanSeconds): int;
}
