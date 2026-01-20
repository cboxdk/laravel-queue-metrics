<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\WorkerStopping;
use Cbox\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;

/**
 * Listen for worker stopping events.
 * Tracks worker shutdown for uptime and stability metrics.
 * Supports both standard queue workers and Laravel Horizon.
 */
final readonly class WorkerStoppingListener
{
    public function __construct(
        private TransitionWorkerStateAction $transitionWorkerState,
    ) {}

    public function handle(WorkerStopping $event): void
    {
        $workerId = $this->getWorkerId();

        // Transition worker to STOPPED state
        // This allows tracking:
        // - Worker uptime (from first heartbeat to stopped)
        // - Worker stability metrics
        $this->transitionWorkerState->execute(
            workerId: $workerId,
            newState: WorkerState::STOPPED,
        );
    }

    private function getWorkerId(): string
    {
        return HorizonDetector::generateWorkerId();
    }
}
