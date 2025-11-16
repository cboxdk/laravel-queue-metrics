<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;

/**
 * Listen for successfully processed jobs.
 */
final readonly class JobProcessedListener
{
    public function __construct(
        private RecordJobCompletionAction $recordJobCompletion,
    ) {}

    public function handle(JobProcessed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();

        // Calculate duration and memory
        $startTime = $payload['pushedAt'] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;

        $this->recordJobCompletion->execute(
            jobId: $job->getJobId() ?? uniqid('job_', true),
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $event->connectionName,
            queue: $job->getQueue() ?? 'default',
            durationMs: $durationMs,
            memoryMb: $memoryMb,
        );
    }
}
