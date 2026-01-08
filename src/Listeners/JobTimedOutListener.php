<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobTimedOut;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Listen for job timeout events.
 * Tracks jobs that exceed their configured timeout.
 */
final readonly class JobTimedOutListener
{
    public function __construct(
        private JobMetricsRepository $jobMetricsRepository,
    ) {}

    public function handle(JobTimedOut $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        $jobId = (string) $job->getJobId();
        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $connection = $event->connectionName;
        $queue = $job->getQueue();

        // Record timeout
        // This helps identify:
        // - Jobs with insufficient timeout configuration
        // - Long-running job patterns
        // - Performance degradation (jobs taking longer over time)
        $this->jobMetricsRepository->recordTimeout(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            timedOutAt: Carbon::now(),
        );
    }
}
