<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;

/**
 * Service for querying worker-specific metrics.
 */
final readonly class WorkerMetricsQueryService
{
    public function __construct(
        private WorkerRepository $workerRepository,
        private WorkerHeartbeatRepository $workerHeartbeatRepository,
        private JobMetricsRepository $jobMetricsRepository,
        private TrendAnalysisService $trendAnalysis,
        private ServerMetricsService $serverMetricsService,
    ) {}

    /**
     * Get all active workers with their stats.
     *
     * @return Collection<int, WorkerStatsData>
     */
    public function getActiveWorkers(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    /**
     * Get all worker heartbeats.
     *
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getWorkerHeartbeats(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerHeartbeatRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    /**
     * Get a specific worker heartbeat by ID.
     */
    public function getWorkerHeartbeat(string $workerId): ?WorkerHeartbeat
    {
        return $this->workerHeartbeatRepository->getWorker($workerId);
    }

    /**
     * Detect staled workers (haven't sent heartbeat in threshold seconds).
     */
    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        return $this->workerHeartbeatRepository->detectStaledWorkers($thresholdSeconds);
    }

    /**
     * Get all workers with detailed metrics.
     *
     * @return array<string, mixed>
     */
    public function getAllWorkersWithMetrics(): array
    {
        $summary = $this->getWorkersSummary();

        // Get all active workers from heartbeat repository
        $activeWorkers = $this->workerHeartbeatRepository->getActiveWorkers();

        // Convert WorkerHeartbeat objects to array format
        $workersArray = $activeWorkers->map(function ($worker) {
            return [
                'worker_id' => $worker->workerId,
                'connection' => $worker->connection,
                'queue' => $worker->queue,
                'state' => $worker->state->value,
                'pid' => $worker->pid,
                'hostname' => $worker->hostname,
                'last_heartbeat' => $worker->lastHeartbeat->toIso8601String(),
                'last_state_change' => $worker->lastStateChange?->toIso8601String(),
                'current_job_id' => $worker->currentJobId,
                'current_job_class' => $worker->currentJobClass,
                'jobs_processed' => $worker->jobsProcessed,
                'idle_time_seconds' => $worker->idleTimeSeconds,
                'busy_time_seconds' => $worker->busyTimeSeconds,
                'memory_usage_mb' => $worker->memoryUsageMb,
                'cpu_usage_percent' => $worker->cpuUsagePercent,
                'peak_memory_usage_mb' => $worker->peakMemoryUsageMb,
                'seconds_since_last_heartbeat' => $worker->secondsSinceLastHeartbeat(),
                'is_stale' => $worker->isStale(),
            ];
        })->values()->all();

        return [
            'summary' => $summary,
            'workers' => $workersArray,
        ];
    }

    /**
     * Get workers summary with totals and averages.
     *
     * @return array<string, mixed>
     */
    public function getWorkersSummary(): array
    {
        // Use WorkerHeartbeat for accurate time tracking
        $heartbeats = $this->workerHeartbeatRepository->getActiveWorkers();
        $total = count($heartbeats);
        $active = 0;
        $idle = 0;
        $totalJobsProcessed = 0;
        $totalIdleTimeSeconds = 0;
        $totalBusyTimeSeconds = 0;

        foreach ($heartbeats as $heartbeat) {
            // Count by state
            if ($heartbeat->state === WorkerState::BUSY) {
                $active++;
            } else {
                $idle++;
            }

            // Aggregate actual time in seconds (not percentages!)
            $totalJobsProcessed += $heartbeat->jobsProcessed;
            $totalIdleTimeSeconds += $heartbeat->idleTimeSeconds;
            $totalBusyTimeSeconds += $heartbeat->busyTimeSeconds;
        }

        $avgJobsPerWorker = $total > 0 ? $totalJobsProcessed / $total : 0.0;

        $totalTimeSeconds = $totalIdleTimeSeconds + $totalBusyTimeSeconds;
        $lifetimeBusyPercentage = $totalTimeSeconds > 0 ? ($totalBusyTimeSeconds / $totalTimeSeconds) * 100 : 0.0;
        $currentBusyPercentage = $total > 0 ? ($active / $total) * 100 : 0.0;

        return [
            'count' => [
                'total' => $total,
                'active' => $active,
                'idle' => $idle,
            ],
            'utilization' => [
                'current_busy_percent' => round($currentBusyPercentage, 2),
                'lifetime_busy_percent' => round($lifetimeBusyPercentage, 2),
            ],
            'performance' => [
                'avg_jobs_per_worker' => round($avgJobsPerWorker, 2),
                'total_jobs_processed' => $totalJobsProcessed,
            ],
        ];
    }

    /**
     * Get all servers with aggregated metrics per hostname.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllServersWithMetrics(): array
    {
        $servers = [];
        $heartbeats = $this->workerHeartbeatRepository->getActiveWorkers();

        // Group workers by hostname
        foreach ($heartbeats as $heartbeat) {
            $hostname = $heartbeat->hostname ?? 'unknown';

            if (! isset($servers[$hostname])) {
                $servers[$hostname] = [
                    'hostname' => $hostname,
                    // Application tier: Queue worker counts and states
                    'queue_workers' => [
                        'count' => ['total' => 0, 'active' => 0, 'idle' => 0],
                        'utilization' => [
                            'current_busy_percent' => 0.0, // % of workers busy right now
                            'lifetime_busy_percent' => 0.0, // % of time workers have been busy
                        ],
                    ],
                    // Application tier: Job processing metrics
                    'job_processing' => [
                        'lifetime' => [
                            'total_processed' => 0,
                            'total_failed' => 0,
                            'failure_rate_percent' => 0.0,
                        ],
                        'current' => [
                            'jobs_per_minute' => 0.0, // Based on actual elapsed time
                            'avg_duration_ms' => 0.0,
                        ],
                    ],
                    // Process tier: Worker process resource usage
                    'worker_processes' => [
                        'avg_memory_per_worker_mb' => 0.0,
                        'avg_cpu_per_worker_percent' => 0.0,
                        'peak_memory_mb' => 0.0,
                        'total_memory_mb' => 0.0, // Sum across all workers
                    ],
                    // Capacity planning
                    'capacity' => [
                        'recommendation' => null,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ];
            }

            $servers[$hostname]['queue_workers']['count']['total']++;

            // Count by state
            if ($heartbeat->state === WorkerState::BUSY) {
                $servers[$hostname]['queue_workers']['count']['active']++;
            } else {
                $servers[$hostname]['queue_workers']['count']['idle']++;
            }

            // Aggregate job processing metrics from heartbeat
            $servers[$hostname]['job_processing']['lifetime']['total_processed'] += $heartbeat->jobsProcessed;

            // Aggregate worker process resource metrics from heartbeat
            $servers[$hostname]['worker_processes']['total_memory_mb'] += $heartbeat->memoryUsageMb;
            $servers[$hostname]['worker_processes']['peak_memory_mb'] = max(
                $servers[$hostname]['worker_processes']['peak_memory_mb'],
                $heartbeat->peakMemoryUsageMb
            );
            $servers[$hostname]['worker_processes']['avg_cpu_per_worker_percent'] += $heartbeat->cpuUsagePercent;
            $servers[$hostname]['worker_processes']['avg_memory_per_worker_mb'] += $heartbeat->memoryUsageMb;
        }

        // Calculate averages, utilization, and performance per server
        foreach ($servers as $hostname => &$server) {
            $totalWorkers = $server['queue_workers']['count']['total'];
            // @phpstan-ignore-next-line - Defensive check even though PHPStan knows totalWorkers >= 1
            if ($totalWorkers > 0) {
                // Calculate worker process resource averages
                $server['worker_processes']['avg_memory_per_worker_mb'] =
                    $server['worker_processes']['total_memory_mb'] / $totalWorkers;
                $server['worker_processes']['avg_cpu_per_worker_percent'] =
                    $server['worker_processes']['avg_cpu_per_worker_percent'] / $totalWorkers;

                // Calculate current worker utilization (% busy right now)
                $activeWorkers = $server['queue_workers']['count']['active'];
                $server['queue_workers']['utilization']['current_busy_percent'] =
                    round(($activeWorkers / $totalWorkers) * 100, 2);

                // Calculate lifetime worker efficiency (% of time spent busy)
                $hostnameWorkers = collect($heartbeats)->filter(
                    fn ($hb) => $hb->hostname === $hostname
                );

                $totalBusyTime = $hostnameWorkers->sum(fn ($hb) => $hb->busyTimeSeconds);
                $totalIdleTime = $hostnameWorkers->sum(fn ($hb) => $hb->idleTimeSeconds);
                $totalTime = $totalBusyTime + $totalIdleTime;

                $server['queue_workers']['utilization']['lifetime_busy_percent'] = $totalTime > 0
                    ? round(($totalBusyTime / $totalTime) * 100, 2)
                    : 0.0;

                // Calculate jobs_per_minute from actual elapsed time
                $oldestWorkerUptimeSeconds = $hostnameWorkers->max(
                    fn ($hb) => $hb->busyTimeSeconds + $hb->idleTimeSeconds
                );

                // Type safety: max() can return mixed, ensure numeric
                $uptimeSeconds = is_numeric($oldestWorkerUptimeSeconds) ? (float) $oldestWorkerUptimeSeconds : 0.0;

                $elapsedMinutes = $uptimeSeconds > 0
                    ? $uptimeSeconds / 60
                    : 0;

                $server['job_processing']['current']['jobs_per_minute'] = $elapsedMinutes > 0
                    ? round($server['job_processing']['lifetime']['total_processed'] / $elapsedMinutes, 2)
                    : 0.0;

                // Get hostname-scoped job metrics for detailed job performance
                $jobMetrics = $this->jobMetricsRepository->getHostnameJobMetrics($hostname);
                $totalJobsFailed = 0;
                $totalDurationMs = 0.0;
                $totalJobsProcessed = 0;

                foreach ($jobMetrics as $metrics) {
                    $totalJobsFailed += $metrics['total_failed'];
                    $totalDurationMs += $metrics['total_duration_ms'];
                    $totalJobsProcessed += $metrics['total_processed'];
                }

                $server['job_processing']['lifetime']['total_failed'] = $totalJobsFailed;
                $totalJobs = $totalJobsProcessed + $totalJobsFailed;
                $server['job_processing']['lifetime']['failure_rate_percent'] = $totalJobs > 0
                    ? round(($totalJobsFailed / $totalJobs) * 100, 2)
                    : 0.0;
                $server['job_processing']['current']['avg_duration_ms'] = $totalJobsProcessed > 0
                    ? round($totalDurationMs / $totalJobsProcessed, 2)
                    : 0.0;

                // Add capacity recommendation based on current utilization
                $currentUtilization = $server['queue_workers']['utilization']['current_busy_percent'] / 100;
                if ($currentUtilization > 90) {
                    $server['capacity']['recommendation'] =
                        'Consider horizontal scaling: Add more workers or servers';
                } elseif ($currentUtilization < 30) {
                    $server['capacity']['recommendation'] =
                        'Consider reducing worker count to optimize resource usage';
                }
            }

            // System tier: Add actual server resources (only for current server)
            // Note: This gets system resources for the current server running this code
            // For multi-server setups, we'd need each server to report its own resources
            // Uses fast getSystemLimits() with 5-second cache to avoid macOS CPU polling issues

            // Try to match hostname (gethostname() might return different format than worker)
            $currentHostname = gethostname();
            $hostnameMatch = false;

            if ($currentHostname !== false) {
                $hostnameMatch = $hostname === $currentHostname
                    || str_starts_with($hostname, $currentHostname)
                    || str_starts_with($currentHostname, $hostname);
            }

            if ($hostnameMatch) {
                $systemLimits = $this->serverMetricsService->getSystemLimits();
                if ($systemLimits['available']) {
                    $server['server_resources'] = $systemLimits;
                }
            }
        }

        return $servers;
    }

    /**
     * Get worker efficiency trend data.
     *
     * @return array<string, mixed>
     */
    public function getWorkerEfficiencyTrend(int $periodSeconds = 3600): array
    {
        return $this->trendAnalysis->analyzeWorkerEfficiencyTrend($periodSeconds);
    }
}
