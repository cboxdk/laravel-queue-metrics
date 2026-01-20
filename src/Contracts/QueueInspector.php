<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Contracts;

use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;

/**
 * Contract for inspecting queue depth and job statistics.
 */
interface QueueInspector
{
    /**
     * Get queue depth statistics for a specific queue.
     */
    public function getQueueDepth(string $connection, string $queue): QueueDepthData;

    /**
     * Check if a queue has any jobs.
     */
    public function hasJobs(string $connection, string $queue): bool;

    /**
     * Get all configured queue names.
     *
     * @return array<string>
     */
    public function getAllQueues(): array;
}
