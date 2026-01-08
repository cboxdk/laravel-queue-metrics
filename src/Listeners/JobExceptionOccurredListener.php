<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Carbon\Carbon;
use Illuminate\Queue\Events\JobExceptionOccurred;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Listen for exceptions during job execution.
 * Tracks exceptions that occur before job is marked as failed.
 */
final readonly class JobExceptionOccurredListener
{
    public function __construct(
        private JobMetricsRepository $jobMetricsRepository,
    ) {}

    public function handle(JobExceptionOccurred $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        $jobId = (string) $job->getJobId();
        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $exception = $event->exception;

        // Record exception occurrence
        // This helps track:
        // - Exception types and frequencies
        // - Transient vs permanent errors
        // - Error patterns before eventual failure
        // - Recovery attempts (job may continue or be retried)
        $this->jobMetricsRepository->recordException(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            exceptionClass: get_class($exception),
            exceptionMessage: $exception->getMessage(),
            occurredAt: Carbon::now(),
        );
    }
}
