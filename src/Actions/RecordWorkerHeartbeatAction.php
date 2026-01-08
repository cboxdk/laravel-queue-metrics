<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\SystemMetrics\ProcessMetrics;

/**
 * Record worker heartbeat with current state.
 */
final readonly class RecordWorkerHeartbeatAction
{
    public function __construct(
        private WorkerHeartbeatRepository $repository,
    ) {}

    public function execute(
        string $workerId,
        string $connection,
        string $queue,
        WorkerState $state,
        string|int|null $currentJobId = null,
        ?string $currentJobClass = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        $pid = getmypid();
        if ($pid === false) {
            $pid = 0;
        }

        // Collect per-worker resource metrics
        $memoryUsageMb = memory_get_usage(true) / 1024 / 1024;
        $cpuUsagePercent = 0.0;

        if ($pid > 0) {
            $trackerId = "worker_{$workerId}";
            $metricsResult = ProcessMetrics::snapshot($pid);

            if ($metricsResult->isSuccess()) {
                $snapshot = $metricsResult->getValue();
                $memoryUsageMb = $snapshot->resources->memoryRssBytes / 1024 / 1024;

                // Calculate CPU usage percentage from process times
                $cpuTimes = $snapshot->resources->cpuTimes;
                $totalCpuTimeMs = $cpuTimes->user + $cpuTimes->system;

                // Get uptime estimate (simplified - actual calculation would need previous snapshot)
                if ($totalCpuTimeMs > 0) {
                    // Approximate CPU % based on cumulative CPU time
                    // This is a snapshot, so we can't calculate true % without previous measurement
                    // Use cumulative time as indicator
                    $cpuUsagePercent = min(100.0, ($totalCpuTimeMs / 1000.0) / 10.0);
                }
            }
        }

        $this->repository->recordHeartbeat(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: $state,
            currentJobId: $currentJobId,
            currentJobClass: $currentJobClass,
            pid: $pid,
            hostname: gethostname() ?: 'unknown',
            memoryUsageMb: $memoryUsageMb,
            cpuUsagePercent: $cpuUsagePercent,
        );
    }
}
