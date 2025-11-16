<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\SystemMetrics\ProcessMetrics;

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
        $jobId = $job->getJobId() ?? uniqid('job_', true);

        // Start tracking process metrics for this job
        ProcessMetrics::start(
            pid: getmypid(),
            trackerId: "job_{$jobId}",
            includeChildren: false
        );

        $this->recordJobStart->execute(
            jobId: $jobId,
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $event->connectionName,
            queue: $job->getQueue() ?? 'default',
        );
    }
}
