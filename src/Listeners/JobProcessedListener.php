<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\SystemMetrics\ProcessMetrics;

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
        $jobId = $job->getJobId() ?? uniqid('job_', true);

        // Calculate duration
        $startTime = $payload['pushedAt'] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        // Get system metrics from ProcessMetrics tracker
        $trackerId = "job_{$jobId}";
        $metricsResult = ProcessMetrics::stop($trackerId);

        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();
            // Get actual memory from system metrics if available
            $memoryMb = $metrics->memory->currentBytes / 1024 / 1024;
            // Calculate CPU time in milliseconds
            $cpuTimeMs = $metrics->cpu->totalCpuTimeSeconds * 1000;
        }

        $this->recordJobCompletion->execute(
            jobId: $jobId,
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $event->connectionName,
            queue: $job->getQueue() ?? 'default',
            durationMs: $durationMs,
            memoryMb: $memoryMb,
            cpuTimeMs: $cpuTimeMs,
        );
    }
}
