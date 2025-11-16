<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;

/**
 * Listen for jobs starting to process.
 */
final readonly class JobProcessingListener
{
    public function __construct(
        private RecordJobStartAction $recordJobStart,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        $this->recordJobStart->execute(
            jobId: $job->getJobId() ?? uniqid('job_', true),
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $event->connectionName,
            queue: $job->getQueue() ?? 'default',
        );
    }
}
