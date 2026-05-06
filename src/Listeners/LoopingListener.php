<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Support\HeartbeatThrottleCache;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Illuminate\Queue\Events\Looping;

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
        if (! config('queue-metrics.persistence.enabled', true)) {
            return;
        }

        $workerId = HorizonDetector::generateWorkerId();
        $connection = $event->connectionName;
        $queue = $event->queue;

        if (HeartbeatThrottleCache::shouldSkip($workerId, WorkerState::IDLE->value, time())) {
            return;
        }

        $this->recordWorkerHeartbeat->execute(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: WorkerState::IDLE,
            currentJobId: null,
            currentJobClass: null,
        );

        HeartbeatThrottleCache::record($workerId, WorkerState::IDLE->value, time());
    }
}
