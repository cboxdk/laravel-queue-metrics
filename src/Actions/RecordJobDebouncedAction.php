<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Record when a debounced job is discarded (superseded by a newer dispatch).
 */
final readonly class RecordJobDebouncedAction
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

        $this->repository->recordDebounced(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            debouncedAt: Carbon::now(),
        );
    }
}
