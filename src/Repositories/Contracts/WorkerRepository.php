<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories\Contracts;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;

/**
 * Repository contract for worker tracking.
 */
interface WorkerRepository
{
    /**
     * Register a worker.
     */
    public function registerWorker(
        int $pid,
        string $hostname,
        string $connection,
        string $queue,
        Carbon $spawnedAt,
    ): void;

    /**
     * Update worker activity.
     */
    public function updateWorkerActivity(
        int $pid,
        string $status,
        ?string $currentJob = null,
        int $jobsProcessed = 0,
        float $idlePercentage = 0.0,
    ): void;

    /**
     * Unregister a worker.
     */
    public function unregisterWorker(int $pid): void;

    /**
     * Get statistics for a specific worker.
     */
    public function getWorkerStats(int $pid): ?WorkerStatsData;

    /**
     * Get all active workers.
     *
     * @return array<int, WorkerStatsData>
     */
    public function getActiveWorkers(?string $connection = null, ?string $queue = null): array;

    /**
     * Count active workers for a queue.
     */
    public function countActiveWorkers(string $connection, string $queue): int;

    /**
     * Clean up stale worker records.
     */
    public function cleanupStaleWorkers(int $olderThanSeconds): int;
}
