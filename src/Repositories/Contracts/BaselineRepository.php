<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories\Contracts;

use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;

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
     * Get baseline for a queue.
     */
    public function getBaseline(string $connection, string $queue): ?BaselineData;

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
