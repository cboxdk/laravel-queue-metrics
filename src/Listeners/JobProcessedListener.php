<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;
use Cbox\LaravelQueueMetrics\Support\JobMetricsCollector;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\LaravelQueueMetrics\Utilities\MemoryLimitParser;
use Illuminate\Queue\Events\JobProcessed;

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
        $jobId = (string) $job->getJobId();

        // Skip performance metrics for debounced (superseded) jobs.
        // The JobDebouncedListener already recorded the debounce counter.
        if (DebouncedJobTracker::wasDebounced($jobId)) {
            return;
        }

        $snapshot = JobMetricsCollector::collect($jobId, $payload);

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';
        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $persistenceEnabled = config('queue-metrics.persistence.enabled', true);

        if ($persistenceEnabled) {
            $this->recordJobCompletion->execute(
                jobId: $jobId,
                jobClass: $jobClass,
                connection: $connection,
                queue: $queue,
                durationMs: $snapshot->durationMs,
                memoryMb: $snapshot->memoryMb,
                memoryIncrementalMb: $snapshot->memoryIncrementalMb,
                cpuTimeMs: $snapshot->cpuTimeMs,
                hostname: $hostname,
            );
        }

        // Fire per-job metrics event for downstream consumers (e.g., queue-monitor)
        JobMetricsCompleted::dispatch(
            $jobId,
            $jobClass,
            $connection,
            $queue,
            $snapshot->durationMs,
            $snapshot->memoryMb,
            $snapshot->cpuTimeMs,
            $hostname,
            MemoryLimitParser::getCurrentLimitMb(),
            $snapshot->memoryIncrementalMb,
        );

        // Record worker heartbeat with IDLE state (job completed)
        if ($persistenceEnabled) {
            $workerId = HorizonDetector::generateWorkerId();
            $this->recordWorkerHeartbeat->execute(
                workerId: $workerId,
                connection: $connection,
                queue: $queue,
                state: WorkerState::IDLE,
                currentJobId: null,
                currentJobClass: null,
            );
        }
    }
}
