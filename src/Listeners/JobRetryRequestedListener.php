<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobRetryRequested;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Listen for job retry requests.
 * Tracks retry patterns and transient failure recovery.
 */
final readonly class JobRetryRequestedListener
{
    public function __construct(
        private JobMetricsRepository $jobMetricsRepository,
    ) {}

    public function handle(JobRetryRequested $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        $jobId = $job->getJobId();
        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $connection = $event->connectionName;
        $queue = $job->getQueue();

        // Record retry request
        // This helps track:
        // - Retry frequency per job class
        // - Transient failure patterns
        // - Recovery success rates
        $this->jobMetricsRepository->recordRetryRequested(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            retryRequestedAt: Carbon::now(),
            attemptNumber: $payload['attempts'] ?? 1,
        );
    }
}
