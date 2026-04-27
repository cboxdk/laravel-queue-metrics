<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobDebouncedAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\SystemMetrics\ProcessMetrics;

/**
 * Listen for debounced (superseded) jobs.
 *
 * When a debounced job is discarded at execution time, this listener:
 * 1. Marks the job so JobProcessedListener skips performance metrics
 * 2. Stops the ProcessMetrics tracker started by JobProcessingListener
 * 3. Records a total_debounced counter
 * 4. Fires a downstream event for consumers
 *
 * Requires Laravel 13.6+ (Illuminate\Queue\Events\JobDebounced).
 */
final readonly class JobDebouncedListener
{
    public function __construct(
        private RecordJobDebouncedAction $recordJobDebounced,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(object $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        // Mark this job so JobProcessedListener skips performance metrics
        DebouncedJobTracker::mark($jobId);

        // Stop process metrics tracker (cleanup from JobProcessingListener)
        $trackerId = "job_{$jobId}";
        ProcessMetrics::stop($trackerId);

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';
        $jobClass = $payload['displayName'] ?? 'UnknownJob';

        if (config('queue-metrics.persistence.enabled', true)) {
            $this->recordJobDebounced->execute(
                jobId: $jobId,
                jobClass: $jobClass,
                connection: $connection,
                queue: $queue,
            );

            // Record worker heartbeat as IDLE (debounced job is done)
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

        // Fire downstream event (always, regardless of persistence setting)
        JobMetricsDebounced::dispatch(
            $jobId,
            $jobClass,
            $connection,
            $queue,
            $hostname,
        );
    }
}
