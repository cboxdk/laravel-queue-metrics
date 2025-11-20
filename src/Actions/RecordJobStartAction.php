<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

/**
 * Record when a job starts processing.
 */
final readonly class RecordJobStartAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private QueueMetricsRepository $queueMetricsRepository,
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Queue discovery now happens atomically inside recordStart()
        $this->repository->recordStart(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            startedAt: Carbon::now(),
        );
    }
}
