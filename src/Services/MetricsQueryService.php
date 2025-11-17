<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\Contracts\QueueInspector;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;

/**
 * Primary service for querying queue metrics.
 */
final readonly class MetricsQueryService
{
    public function __construct(
        private CalculateJobMetricsAction $calculateJobMetrics,
        private JobMetricsRepository $jobMetricsRepository,
        private QueueMetricsRepository $queueMetricsRepository,
        private WorkerRepository $workerRepository,
        private BaselineRepository $baselineRepository,
        private WorkerHeartbeatRepository $workerHeartbeatRepository,
        private QueueInspector $queueInspector,
    ) {}

    public function getJobMetrics(
        string $jobClass,
        string $connection = 'default',
        string $queue = 'default',
    ): JobMetricsData {
        return $this->calculateJobMetrics->execute($jobClass, $connection, $queue);
    }

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
     * @return Collection<int, WorkerStatsData>
     */
    public function getActiveWorkers(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    public function getBaseline(
        string $connection,
        string $queue,
    ): ?BaselineData {
        return $this->baselineRepository->getBaseline($connection, $queue);
    }

    /**
     * @return array{
     *     total_queues: int,
     *     total_jobs_processed: int,
     *     total_jobs_failed: int,
     *     total_active_workers: int,
     *     health_score: float
     * }
     */
    public function getOverview(): array
    {
        $queues = $this->queueMetricsRepository->listQueues();
        $workers = $this->workerRepository->getActiveWorkers();

        // Aggregate job counts from all job metrics keys in Redis
        $totalProcessed = 0;
        $totalFailed = 0;

        // Scan Redis for all job metrics keys and aggregate
        $manager = app(\PHPeek\LaravelQueueMetrics\Storage\StorageManager::class);

        // Laravel's Redis connection automatically adds prefix for KEYS command
        // So we only use our package prefix, not Laravel's Redis prefix
        $pattern = $manager->key('jobs', '*', '*', '*');

        $driver = $manager->driver();

        // Get all job metrics keys
        $keys = $driver->scanKeys($pattern);

        // Laravel's Redis prefix for stripping
        $laravelPrefix = config('database.redis.options.prefix', '');

        // Sum metrics from all keys
        foreach ($keys as $fullKey) {
            // keys() returns keys WITH Laravel prefix already applied
            // But getHash() will ADD prefix again, so we need to strip it first
            $relativeKey = $laravelPrefix !== ''
                ? str_replace($laravelPrefix, '', $fullKey)
                : $fullKey;

            $data = $driver->getHash($relativeKey);
            if (is_array($data)) {
                $totalProcessed += (int) ($data['total_processed'] ?? 0);
                $totalFailed += (int) ($data['total_failed'] ?? 0);
            }
        }

        return [
            'total_queues' => count($queues),
            'total_jobs_processed' => $totalProcessed,
            'total_jobs_failed' => $totalFailed,
            'total_active_workers' => count($workers),
            'health_score' => 100.0,
        ];
    }

    /**
     * @return array{status: string, timestamp: int}
     */
    public function healthCheck(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => time(),
        ];
    }

    public function getQueueDepth(
        string $connection = 'default',
        string $queue = 'default',
    ): QueueDepthData {
        return $this->queueInspector->getQueueDepth($connection, $queue);
    }

    /**
     * @return array<string>
     */
    public function getAllQueues(): array
    {
        return $this->queueInspector->getAllQueues();
    }

    /**
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getWorkerHeartbeats(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $workers = $this->workerHeartbeatRepository->getActiveWorkers($connection, $queue);

        return collect($workers);
    }

    public function getWorkerHeartbeat(string $workerId): ?WorkerHeartbeat
    {
        return $this->workerHeartbeatRepository->getWorker($workerId);
    }

    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        return $this->workerHeartbeatRepository->detectStaledWorkers($thresholdSeconds);
    }
}
