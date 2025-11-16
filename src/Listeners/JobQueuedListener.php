<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobQueued;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Listen for jobs being queued.
 * Tracks when jobs are added to queue for time-to-start metrics.
 */
final readonly class JobQueuedListener
{
    public function __construct(
        private JobMetricsRepository $jobMetricsRepository,
    ) {}

    public function handle(JobQueued $event): void
    {
        $connection = $event->connectionName;
        $queue = $event->job->queue ?? 'default';
        $jobClass = get_class($event->job);

        // Store queued timestamp for time-to-start calculation
        // When JobProcessing fires, we can calculate: processing_started - queued_at
        $this->jobMetricsRepository->recordQueuedAt(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            queuedAt: Carbon::now(),
        );
    }
}
