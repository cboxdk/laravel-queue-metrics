<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Illuminate\Queue\Events\JobQueued;

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

        // Job can be an object or a string depending on the queue driver
        $job = $event->job;
        $jobClass = is_object($job) ? get_class($job) : (string) $job;

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
