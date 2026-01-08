<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Record when a job starts processing.
 */
final readonly class RecordJobStartAction
{
    public function __construct(
        private JobMetricsRepository $repository,
    ) {}

    public function execute(
        string|int $jobId,
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
