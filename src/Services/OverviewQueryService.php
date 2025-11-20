<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Services\Contracts\OverviewQueryInterface;

/**
 * Service that orchestrates comprehensive overview by combining job, queue, and worker metrics.
 */
final readonly class OverviewQueryService implements OverviewQueryInterface
{
    public function __construct(
        private JobMetricsQueryService $jobMetricsQuery,
        private QueueMetricsQueryService $queueMetricsQuery,
        private WorkerMetricsQueryService $workerMetricsQuery,
        private BaselineRepository $baselineRepository,
        private TrendAnalysisService $trendAnalysisService,
    ) {}

    /**
     * Get comprehensive overview with queues, jobs, servers, and workers.
     * Includes baselines for all discovered queue-connection pairs.
     * Optimized with parallel fetching to minimize latency.
     *
     * @param  bool  $slim  Return minimal dashboard fields only (default: true)
     * @return array{
     *     queues: array<string, array<string, mixed>>,
     *     jobs: array<string, array<string, mixed>>,
     *     servers: array<string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines?: array<string, array<string, mixed>>,
     *     trends?: array<string, mixed>,
     *     metadata: array{timestamp: string, package_version: string, laravel_version: string, storage_driver: mixed}
     * }
     */
    public function getOverview(bool $slim = true): array
    {
        // Fetch all data - queues first to determine baseline pairs
        $queues = $this->queueMetricsQuery->getAllQueuesWithMetrics();
        $jobs = $this->jobMetricsQuery->getAllJobsWithMetrics();
        $servers = $this->workerMetricsQuery->getAllServersWithMetrics();
        $workers = $this->workerMetricsQuery->getWorkersSummary();

        // Apply slim filtering if requested
        if ($slim) {
            $queues = $this->filterQueuesForDashboard($queues);
            $jobs = $this->filterJobsForDashboard($jobs);
            $servers = $this->filterServersForDashboard($servers);
            $workers = $this->filterWorkersForDashboard($workers);

            return [
                'queues' => $queues,
                'jobs' => $jobs,
                'servers' => $servers,
                'workers' => $workers,
                'metadata' => [
                    'timestamp' => now()->toIso8601String(),
                    'package_version' => '1.0.0',
                    'laravel_version' => app()->version(),
                    'storage_driver' => config('queue-metrics.storage.driver', 'redis'),
                ],
            ];
        }

        // Full view includes baselines and trends
        $queuePairs = array_values(array_map(
            fn ($queueData) => [
                'connection' => is_string($queueData['connection'] ?? null) ? $queueData['connection'] : 'redis',
                'queue' => is_string($queueData['queue'] ?? null) ? $queueData['queue'] : 'default',
            ],
            $queues
        ));

        // Fetch all baselines in a single batch operation using Redis pipeline
        $baselineObjects = $this->baselineRepository->getBaselines($queuePairs);
        $baselines = array_map(fn ($baseline) => $baseline->toArray(), $baselineObjects);

        // Fetch trend data for ALL queues
        $queueDepthTrends = [];
        foreach ($queuePairs as $queuePair) {
            try {
                $connection = $queuePair['connection'];
                $queue = $queuePair['queue'];
                $key = "{$connection}:{$queue}";

                $queueDepthTrends[$key] = $this->trendAnalysisService->analyzeQueueDepthTrend(
                    $connection,
                    $queue
                );
            } catch (\Throwable $e) {
                // Skip queues that fail, continue with others
                continue;
            }
        }

        // Fetch worker efficiency trend (global, not per-queue)
        $workerEfficiencyTrend = [];
        try {
            $workerEfficiencyTrend = $this->trendAnalysisService->analyzeWorkerEfficiencyTrend();
        } catch (\Throwable $e) {
            // Worker efficiency is optional
        }

        $trends = [
            'queue_depth' => $queueDepthTrends,
            'worker_efficiency' => $workerEfficiencyTrend,
        ];

        return [
            'queues' => $queues,
            'jobs' => $jobs,
            'servers' => $servers,
            'workers' => $workers,
            'baselines' => $baselines,
            'trends' => $trends,
            'metadata' => [
                'timestamp' => now()->toIso8601String(),
                'package_version' => '1.0.0',
                'laravel_version' => app()->version(),
                'storage_driver' => config('queue-metrics.storage.driver', 'redis'),
            ],
        ];
    }

    /**
     * Filter queue metrics to essential dashboard fields only.
     *
     * @param  array<string, array<string, mixed>>  $queues
     * @return array<string, array<string, mixed>>
     */
    private function filterQueuesForDashboard(array $queues): array
    {
        return array_map(function ($queue) {
            $depth = is_array($queue['depth'] ?? null) ? $queue['depth'] : [];
            $performance60s = is_array($queue['performance_60s'] ?? null) ? $queue['performance_60s'] : [];
            $lifetime = is_array($queue['lifetime'] ?? null) ? $queue['lifetime'] : [];
            $workers = is_array($queue['workers'] ?? null) ? $queue['workers'] : [];

            return [
                'connection' => $queue['connection'] ?? '',
                'queue' => $queue['queue'] ?? '',
                'depth' => $depth['total'] ?? 0,
                'pending' => $depth['pending'] ?? 0,
                'active_workers' => $workers['active_count'] ?? 0,
                'throughput_per_minute' => $performance60s['throughput_per_minute'] ?? 0,
                'failure_rate' => $lifetime['failure_rate_percent'] ?? 0,
                'current_busy_percent' => $workers['current_busy_percent'] ?? 0,
                'lifetime_busy_percent' => $workers['lifetime_busy_percent'] ?? 0,
            ];
        }, $queues);
    }

    /**
     * Filter job metrics to essential dashboard fields only.
     *
     * @param  array<string, array<string, mixed>>  $jobs
     * @return array<string, array<string, mixed>>
     */
    private function filterJobsForDashboard(array $jobs): array
    {
        return array_map(function ($job) {
            $execution = is_array($job['execution'] ?? null) ? $job['execution'] : [];
            $duration = is_array($job['duration'] ?? null) ? $job['duration'] : [];
            $throughput = is_array($job['throughput'] ?? null) ? $job['throughput'] : [];

            return [
                'job_class' => $job['job_class'] ?? '',
                'connection' => $job['connection'] ?? '',
                'queue' => $job['queue'] ?? '',
                'total_processed' => $execution['total_processed'] ?? 0,
                'success_rate' => $execution['success_rate'] ?? 0,
                'failure_rate' => $execution['failure_rate'] ?? 0,
                'avg_duration_ms' => $duration['avg'] ?? 0,
                'throughput_per_minute' => $throughput['per_minute'] ?? 0,
            ];
        }, $jobs);
    }

    /**
     * Filter server metrics to essential dashboard fields only.
     *
     * Returns simplified server data with clear separation between:
     * - Worker metrics: Worker count, utilization (from queue workers)
     * - Job metrics: Jobs processed (from queue workers)
     * - System metrics: Actual server CPU/memory from SystemMetrics (physical server resources)
     *
     * Note: Worker process CPU/memory metrics are NOT included in dashboard as they're not
     * useful for server-level overview. Use server_resources for actual server resource usage.
     *
     * @param  array<string, array<string, mixed>>  $servers
     * @return array<string, array<string, mixed>>
     */
    private function filterServersForDashboard(array $servers): array
    {
        return array_map(function ($server) {
            $queueWorkers = is_array($server['queue_workers'] ?? null) ? $server['queue_workers'] : [];
            $workerCount = is_array($queueWorkers['count'] ?? null) ? $queueWorkers['count'] : [];
            $workerUtilization = is_array($queueWorkers['utilization'] ?? null) ? $queueWorkers['utilization'] : [];
            $jobProcessing = is_array($server['job_processing'] ?? null) ? $server['job_processing'] : [];
            $jobLifetime = is_array($jobProcessing['lifetime'] ?? null) ? $jobProcessing['lifetime'] : [];
            $serverResources = is_array($server['server_resources'] ?? null) ? $server['server_resources'] : null;

            $result = [
                'hostname' => $server['hostname'] ?? '',
                // Worker-level metrics (from queue workers)
                'workers' => [
                    'total' => $workerCount['total'] ?? 0,
                    'active' => $workerCount['active'] ?? 0,
                    'idle' => $workerCount['idle'] ?? 0,
                    'current_busy_percent' => $workerUtilization['current_busy_percent'] ?? 0.0,
                    'lifetime_busy_percent' => $workerUtilization['lifetime_busy_percent'] ?? 0.0,
                ],
                // Job processing metrics (from queue workers)
                'jobs' => [
                    'total_processed' => $jobLifetime['total_processed'] ?? 0,
                    'total_failed' => $jobLifetime['total_failed'] ?? 0,
                    'failure_rate_percent' => $jobLifetime['failure_rate_percent'] ?? 0.0,
                ],
            ];

            // System resource metrics (actual server CPU/memory from SystemMetrics)
            // This is the REAL server usage, not worker process usage
            if ($serverResources !== null) {
                $result['server_resources'] = $serverResources;
            }

            return $result;
        }, $servers);
    }

    /**
     * Filter worker summary to essential dashboard fields only.
     *
     * @param  array<string, mixed>  $workers
     * @return array<string, mixed>
     */
    private function filterWorkersForDashboard(array $workers): array
    {
        $count = is_array($workers['count'] ?? null) ? $workers['count'] : [];
        $utilization = is_array($workers['utilization'] ?? null) ? $workers['utilization'] : [];
        $performance = is_array($workers['performance'] ?? null) ? $workers['performance'] : [];

        return [
            'total' => $count['total'] ?? 0,
            'active' => $count['active'] ?? 0,
            'idle' => $count['idle'] ?? 0,
            'current_busy_percent' => $utilization['current_busy_percent'] ?? 0.0,
            'lifetime_busy_percent' => $utilization['lifetime_busy_percent'] ?? 0.0,
            'total_jobs_processed' => $performance['total_jobs_processed'] ?? 0,
        ];
    }
}
