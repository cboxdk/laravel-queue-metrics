<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Services;

use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;

/**
 * Primary service for querying queue metrics.
 */
final readonly class MetricsQueryService
{
    public function __construct(
        private CalculateJobMetricsAction $calculateJobMetrics,
        private QueueMetricsRepository $queueMetricsRepository,
        private WorkerRepository $workerRepository,
        private BaselineRepository $baselineRepository,
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

        return [
            'total_queues' => count($queues),
            'total_jobs_processed' => 0, // Would aggregate from job metrics
            'total_jobs_failed' => 0,
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
}
