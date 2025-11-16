<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobFailed;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;

/**
 * Listen for failed jobs.
 */
final readonly class JobFailedListener
{
    public function __construct(
        private RecordJobFailureAction $recordJobFailure,
    ) {}

    public function handle(JobFailed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        $this->recordJobFailure->execute(
            jobId: $job->getJobId() ?? uniqid('job_', true),
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $event->connectionName,
            queue: $job->getQueue() ?? 'default',
            exception: $event->exception,
        );
    }
}
