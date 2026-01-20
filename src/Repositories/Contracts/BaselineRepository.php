<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories\Contracts;

use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;

/**
 * Repository contract for baseline metrics storage.
 */
interface BaselineRepository
{
    /**
     * Store baseline calculation.
     */
    public function storeBaseline(BaselineData $baseline): void;

    /**
     * Get baseline for a queue (aggregated across all job classes).
     */
    public function getBaseline(string $connection, string $queue): ?BaselineData;

    /**
     * Get baselines for multiple queues in a single batch operation.
     * Optimized for reducing N+1 queries when fetching multiple baselines.
     *
     * @param  array<int, array{connection: string, queue: string}>  $queuePairs
     * @return array<string, BaselineData> Map of "connection:queue" => BaselineData
     */
    public function getBaselines(array $queuePairs): array;

    /**
     * Get baseline for a specific job class.
     */
    public function getJobClassBaseline(string $connection, string $queue, string $jobClass): ?BaselineData;

    /**
     * Get all job class baselines for a queue.
     *
     * @return array<int, BaselineData>
     */
    public function getJobClassBaselines(string $connection, string $queue): array;

    /**
     * Check if baseline exists and is recent.
     */
    public function hasRecentBaseline(
        string $connection,
        string $queue,
        int $maxAgeSeconds = 86400,
    ): bool;

    /**
     * Delete baseline for a queue.
     */
    public function deleteBaseline(string $connection, string $queue): void;

    /**
     * Clean up old baselines.
     */
    public function cleanup(int $olderThanSeconds): int;
}
