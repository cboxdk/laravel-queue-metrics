<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\Looping;
use PHPeek\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;

/**
 * Listen for worker loop iterations.
 * Tracks worker health and activity through regular heartbeats.
 */
final readonly class LoopingListener
{
    public function __construct(
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(Looping $event): void
    {
        $workerId = $this->getWorkerId();
        $connection = $event->connectionName;
        $queue = $event->queue ?? 'default';

        // Record worker heartbeat on each loop iteration
        // This provides:
        // - Worker liveness detection (via heartbeat timestamps)
        // - Loop frequency metrics
        // - Worker health monitoring
        // - Idle vs busy time tracking
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
        return sprintf(
            'worker_%s_%d',
            gethostname() ?: 'unknown',
            getmypid()
        );
    }
}
