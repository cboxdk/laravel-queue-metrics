<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Utilities\HorizonDetector;
use PHPeek\SystemMetrics\ProcessMetrics;

/**
 * Listen for jobs starting to process.
 */
final readonly class JobProcessingListener
{
    public function __construct(
        private RecordJobStartAction $recordJobStart,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        // Start tracking process metrics for this job (including child processes)
        $pid = getmypid();
        if ($pid !== false) {
            ProcessMetrics::start(
                pid: $pid,
                trackerId: "job_{$jobId}",
                includeChildren: true // Track parent + child processes for accurate metrics
            );
        }

        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $connection = $event->connectionName;
        $queue = $job->getQueue();

        $this->recordJobStart->execute(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
        );

        // Record worker heartbeat with BUSY state
        $workerId = $this->getWorkerId();
        $this->recordWorkerHeartbeat->execute(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: WorkerState::BUSY,
            currentJobId: $jobId,
            currentJobClass: $jobClass,
        );
    }

    private function getWorkerId(): string
    {
        return HorizonDetector::generateWorkerId();
    }
}
