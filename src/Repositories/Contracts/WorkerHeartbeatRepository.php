<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;

/**
 * Repository contract for worker heartbeat storage and retrieval.
 */
interface WorkerHeartbeatRepository
{
    /**
     * Record a worker heartbeat.
     */
    public function recordHeartbeat(
        string $workerId,
        string $connection,
        string $queue,
        WorkerState $state,
        string|int|null $currentJobId,
        ?string $currentJobClass,
        int $pid,
        string $hostname,
        float $memoryUsageMb = 0.0,
        float $cpuUsagePercent = 0.0,
    ): void;

    /**
     * Transition worker to a new state.
     */
    public function transitionState(
        string $workerId,
        WorkerState $newState,
        Carbon $transitionTime,
    ): void;

    /**
     * Get worker heartbeat by ID.
     */
    public function getWorker(string $workerId): ?WorkerHeartbeat;

    /**
     * Get all active workers.
     *
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getActiveWorkers(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection;

    /**
     * Get workers by state.
     *
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getWorkersByState(WorkerState $state): Collection;

    /**
     * Detect and mark stale workers as crashed.
     */
    public function detectStaledWorkers(int $thresholdSeconds = 60): int;

    /**
     * Remove worker from tracking.
     */
    public function removeWorker(string $workerId): void;

    /**
     * Clean up old worker records.
     */
    public function cleanup(int $olderThanSeconds): int;
}
