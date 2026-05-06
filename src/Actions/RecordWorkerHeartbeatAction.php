<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Actions;

use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\CpuSnapshotCache;
use Cbox\LaravelQueueMetrics\Utilities\MemoryConverter;
use Cbox\SystemMetrics\ProcessMetrics;

/**
 * Record worker heartbeat with current state.
 *
 * CPU usage is calculated as a true delta between consecutive heartbeats:
 * (deltaCpuTimeMs / deltaWallTimeMs) * 100. The first heartbeat for a worker
 * establishes the baseline and reports 0%. Values above 100% are valid in
 * multi-core containers where the process can use more than one core's worth
 * of CPU time within a wall-clock interval.
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
        $memoryUsageMb = MemoryConverter::bytesToMegabytes(memory_get_usage(true));
        $cpuUsagePercent = 0.0;

        if ($pid > 0) {
            $metricsResult = ProcessMetrics::snapshot($pid);

            if ($metricsResult->isSuccess()) {
                $snapshot = $metricsResult->getValue();
                $memoryUsageMb = MemoryConverter::bytesToMegabytes($snapshot->resources->memoryRssBytes);

                // Delta-based CPU usage: compare cumulative CPU time between heartbeats
                $cpuTimes = $snapshot->resources->cpuTimes;
                $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
                $now = microtime(true);

                $previous = CpuSnapshotCache::get($workerId);

                if ($previous !== null) {
                    $deltaCpuMs = $totalCpuTimeMs - $previous['cpu_time_ms'];
                    $deltaWallMs = ($now - $previous['wall_time']) * 1000.0;

                    if ($deltaWallMs > 0) {
                        $cpuUsagePercent = ($deltaCpuMs / $deltaWallMs) * 100.0;
                    }
                }

                CpuSnapshotCache::store($workerId, $totalCpuTimeMs, $now);
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
