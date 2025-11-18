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
            return [
                'connection' => $queue['connection'] ?? '',
                'queue' => $queue['queue'] ?? '',
                'depth' => $queue['depth'] ?? 0,
                'pending' => $queue['pending'] ?? 0,
                'active_workers' => $queue['active_workers'] ?? 0,
                'throughput_per_minute' => $queue['throughput_per_minute'] ?? 0,
                'failure_rate' => $queue['failure_rate'] ?? 0,
                'utilization_rate' => $queue['utilization_rate'] ?? 0,
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
     * @param  array<string, array<string, mixed>>  $servers
     * @return array<string, array<string, mixed>>
     */
    private function filterServersForDashboard(array $servers): array
    {
        return array_map(function ($server) {
            $workers = is_array($server['workers'] ?? null) ? $server['workers'] : [];
            $utilization = is_array($server['utilization'] ?? null) ? $server['utilization'] : [];
            $performance = is_array($server['performance'] ?? null) ? $server['performance'] : [];

            $serverUtilization = $utilization['server_utilization'] ?? 0;
            $utilizationPercent = is_numeric($serverUtilization) ? round((float) $serverUtilization * 100, 2) : 0;

            return [
                'hostname' => $server['hostname'] ?? '',
                'workers_total' => $workers['total'] ?? 0,
                'workers_active' => $workers['active'] ?? 0,
                'workers_idle' => $workers['idle'] ?? 0,
                'utilization_percent' => $utilizationPercent,
                'jobs_processed' => $performance['total_jobs_processed'] ?? 0,
            ];
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
        return [
            'total' => $workers['total'] ?? 0,
            'active' => $workers['active'] ?? 0,
            'idle' => $workers['idle'] ?? 0,
            'total_jobs_processed' => $workers['total_jobs_processed'] ?? 0,
        ];
    }
}
