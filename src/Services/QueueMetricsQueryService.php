<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Service for querying queue-specific metrics.
 */
final readonly class QueueMetricsQueryService
{
    public function __construct(
        private QueueMetricsRepository $queueMetricsRepository,
        private BaselineRepository $baselineRepository,
        private QueueInspector $queueInspector,
        private RedisMetricsStore $redisStore,
        private RedisKeyScannerService $keyScanner,
        private WorkerHeartbeatRepository $workerHeartbeatRepository,
    ) {}

    /**
     * Get comprehensive metrics for a specific queue.
     */
    public function getQueueMetrics(
        string $connection = 'default',
        string $queue = 'default',
    ): QueueMetricsData {
        $state = $this->queueMetricsRepository->getQueueState($connection, $queue);
        $metrics = $this->queueMetricsRepository->getLatestMetrics($connection, $queue);
        $health = $this->queueMetricsRepository->getHealthStatus($connection, $queue);

        return QueueMetricsData::fromArray(array_merge($state, $metrics, [
            'connection' => $connection,
            'queue' => $queue,
            'health' => $health,
        ]));
    }

    /**
     * Get queue depth (pending, reserved, delayed jobs).
     */
    public function getQueueDepth(
        string $connection = 'default',
        string $queue = 'default',
    ): QueueDepthData {
        return $this->queueInspector->getQueueDepth($connection, $queue);
    }

    /**
     * Get baseline metrics for a queue.
     */
    public function getBaseline(
        string $connection,
        string $queue,
    ): ?BaselineData {
        return $this->baselineRepository->getBaseline($connection, $queue);
    }

    /**
     * Get all queue names across all connections.
     *
     * @return array<string>
     */
    public function getAllQueues(): array
    {
        return $this->queueInspector->getAllQueues();
    }

    /**
     * Get all queues with full metrics including depth, utilization, etc.
     * Discovers ALL queues by scanning both job metrics AND queued job keys.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllQueuesWithMetrics(): array
    {
        $queues = [];

        // Pattern 1: Completed jobs - queue_metrics:jobs:connection:queue:JobClass
        $jobsPattern = $this->redisStore->key('jobs', '*', '*', '*');

        // Pattern 2: Queued jobs - queue_metrics:queued:connection:queue:JobClass
        $queuedPattern = $this->redisStore->key('queued', '*', '*', '*');

        // Parse keys to extract connection:queue combinations
        $keyParser = function (string $keyWithoutPrefix): ?array {
            // Parse: {jobs|queued}:connection:queue:JobClass
            $parts = explode(':', $keyWithoutPrefix);
            if (count($parts) < 4) {
                return null;
            }

            // parts[0] = jobs|queued
            // parts[1] = connection
            // parts[2] = queue
            return [
                'connection' => $parts[1],
                'queue' => $parts[2],
            ];
        };

        $discoveredQueues = $this->keyScanner->scanAndParseKeys($jobsPattern, $queuedPattern, $keyParser);

        // Build comprehensive metrics for each discovered queue
        foreach ($discoveredQueues as $queueKey => $queueData) {
            $connection = $queueData['connection'];
            $queue = $queueData['queue'];

            try {
                $depth = $this->getQueueDepth($connection, $queue);
                $metrics = $this->getQueueMetrics($connection, $queue);
                $baseline = $this->getBaseline($connection, $queue);

                // Get workers for this specific queue
                $workers = $this->workerHeartbeatRepository
                    ->getActiveWorkers($connection, $queue);

                $activeWorkers = $workers->count();

                // Calculate queue utilization rate from worker busy/idle time
                $totalBusyTime = 0;
                $totalIdleTime = 0;
                foreach ($workers as $worker) {
                    $totalBusyTime += $worker->busyTimeSeconds;
                    $totalIdleTime += $worker->idleTimeSeconds;
                }

                $totalTime = $totalBusyTime + $totalIdleTime;
                $utilizationRate = $totalTime > 0 ? ($totalBusyTime / $totalTime) * 100 : 0;

                $queues[$queueKey] = [
                    'connection' => $connection,
                    'queue' => $queue,
                    'driver' => $connection,
                    'depth' => $depth->totalJobs(),
                    'pending' => $depth->pendingJobs,
                    'scheduled' => $depth->delayedJobs,
                    'reserved' => $depth->reservedJobs,
                    'oldest_job_age_seconds' => $depth->secondsOldestPendingJob() ?? 0,
                    'oldest_job_age_status' => $depth->oldestPendingJobAge?->toIso8601String() ?? 'unknown',
                    'throughput_per_minute' => $metrics->throughputPerMinute,
                    'avg_duration_ms' => $metrics->avgDuration,
                    'failure_rate' => $metrics->failureRate,
                    'utilization_rate' => round($utilizationRate, 2),
                    'active_workers' => $activeWorkers,
                    'baseline' => $baseline ? $baseline->toArray() : null,
                    'timestamp' => now()->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                // Log the exception for debugging
                logger()->error('Failed to get queue metrics', [
                    'connection' => $connection,
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);

                // Skip queues that can't be retrieved
                continue;
            }
        }

        return $queues;
    }

    /**
     * Health check for queue metrics system.
     *
     * @return array{status: string, timestamp: int}
     */
    public function healthCheck(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => time(),
        ];
    }
}
