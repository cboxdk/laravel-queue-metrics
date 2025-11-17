<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Utilities\HorizonDetector;
use PHPeek\SystemMetrics\ProcessMetrics;

/**
 * Listen for successfully processed jobs.
 */
final readonly class JobProcessedListener
{
    public function __construct(
        private RecordJobCompletionAction $recordJobCompletion,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobProcessed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = $job->getJobId();

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
            $memoryMb = $metrics->current->memoryRssBytes / 1024 / 1024;
            // Calculate CPU time in milliseconds
            $cpuTimeMs = $metrics->current->cpuTimes->total() / 100.0; // Convert ticks to ms
        }

        $connection = $event->connectionName;
        $queue = $job->getQueue();

        $this->recordJobCompletion->execute(
            jobId: $jobId,
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $connection,
            queue: $queue,
            durationMs: $durationMs,
            memoryMb: $memoryMb,
            cpuTimeMs: $cpuTimeMs,
        );

        // Record worker heartbeat with IDLE state (job completed)
        $workerId = $this->getWorkerId();
        $this->recordWorkerHeartbeat->execute(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: WorkerState::IDLE,
            currentJobId: null,
            currentJobClass: null,
        );
    }

    private function getWorkerId(): string
    {
        return HorizonDetector::generateWorkerId();
    }
}
