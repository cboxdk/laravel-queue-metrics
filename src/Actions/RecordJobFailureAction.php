<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Throwable;

/**
 * Record when a job fails.
 */
final readonly class RecordJobFailureAction
{
    public function __construct(
        private JobMetricsRepository $repository,
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Throwable $exception,
        ?string $hostname = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        $this->repository->recordFailure(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            exception: $exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine(),
            failedAt: Carbon::now(),
            hostname: $hostname,
        );
    }
}
