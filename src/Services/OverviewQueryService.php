<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Services\Contracts\OverviewQueryInterface;
use PHPeek\LaravelQueueMetrics\Services\TrendAnalysisService;

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
     * @return array{
     *     queues: array<string, array<string, mixed>>,
     *     jobs: array<string, array<string, mixed>>,
     *     servers: array<string, array<string, mixed>>,
     *     workers: array<string, mixed>,
     *     baselines: array<string, array<string, mixed>>,
     *     trends: array<string, mixed>,
     *     metadata: array{timestamp: string, package_version: string, laravel_version: string, storage_driver: mixed}
     * }
     */
    public function getOverview(): array
    {
        // Fetch all data - queues first to determine baseline pairs
        $queues = $this->queueMetricsQuery->getAllQueuesWithMetrics();

        // Extract unique connection:queue pairs for baseline fetching
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

        // Fetch remaining data (can be parallelized by Laravel queue if needed)
        $jobs = $this->jobMetricsQuery->getAllJobsWithMetrics();
        $servers = $this->workerMetricsQuery->getAllServersWithMetrics();
        $workers = $this->workerMetricsQuery->getWorkersSummary();

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
}
