<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Support\JobMetricsCollector;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\LaravelQueueMetrics\Utilities\MemoryLimitParser;
use Illuminate\Queue\Events\JobFailed;

/**
 * Listen for failed jobs.
 */
final readonly class JobFailedListener
{
    public function __construct(
        private RecordJobFailureAction $recordJobFailure,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobFailed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        $snapshot = JobMetricsCollector::collect($jobId, $payload);

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';
        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $persistenceEnabled = config('queue-metrics.persistence.enabled', true);

        if ($persistenceEnabled) {
            $this->recordJobFailure->execute(
                jobId: $jobId,
                jobClass: $jobClass,
                connection: $connection,
                queue: $queue,
                exception: $event->exception,
                hostname: $hostname,
            );
        }

        // Fire per-job metrics event for downstream consumers (e.g., queue-monitor)
        $exceptionMessage = $event->exception->getMessage().' in '.$event->exception->getFile().':'.$event->exception->getLine();

        JobMetricsFailed::dispatch(
            $jobId,
            $jobClass,
            $connection,
            $queue,
            $snapshot->durationMs,
            $snapshot->memoryMb,
            $snapshot->cpuTimeMs,
            $exceptionMessage,
            $hostname,
            MemoryLimitParser::getCurrentLimitMb(),
            $snapshot->memoryIncrementalMb,
        );

        // Record worker heartbeat with IDLE state (job failed, worker ready for next job)
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
