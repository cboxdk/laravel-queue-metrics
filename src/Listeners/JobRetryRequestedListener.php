<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobRetryRequested;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

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
        // $event->job is stdClass - contains raw job data from Laravel queue
        $jobStdClass = $event->job;

        // Extract job metadata from stdClass
        $jobId = is_string($jobStdClass->id ?? null) ? $jobStdClass->id : 'unknown';
        $connection = is_string($jobStdClass->connection ?? null) ? $jobStdClass->connection : 'redis';
        $queue = is_string($jobStdClass->queue ?? null) ? $jobStdClass->queue : 'default';

        // Get payload - event provides helper method that decodes JSON
        /** @var array{displayName?: string, attempts?: int} $payload */
        $payload = $event->payload();

        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $attemptNumber = $payload['attempts'] ?? 1;

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
            attemptNumber: $attemptNumber,
        );
    }
}
