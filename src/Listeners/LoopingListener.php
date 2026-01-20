<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\Looping;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;

/**
 * Listen for worker loop iterations.
 * Tracks worker health and activity through regular heartbeats.
 * Supports both standard queue workers and Laravel Horizon.
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
        $queue = $event->queue; // Property is always set (string type)

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
        return HorizonDetector::generateWorkerId();
    }
}
