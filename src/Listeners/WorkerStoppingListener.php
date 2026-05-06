<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Illuminate\Queue\Events\WorkerStopping;

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
        if (! config('queue-metrics.persistence.enabled', true)) {
            return;
        }

        $this->transitionWorkerState->execute(
            workerId: HorizonDetector::generateWorkerId(),
            newState: WorkerState::STOPPED,
        );
    }
}
