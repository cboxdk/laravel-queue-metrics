<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
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

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';

        $this->recordJobFailure->execute(
            jobId: (string) $job->getJobId(),
            jobClass: $payload['displayName'] ?? 'UnknownJob',
            connection: $connection,
            queue: $queue,
            exception: $event->exception,
            hostname: $hostname,
        );

        // Record worker heartbeat with IDLE state (job failed, worker ready for next job)
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
