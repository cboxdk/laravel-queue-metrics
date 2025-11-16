<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Listeners;

use Illuminate\Queue\Events\WorkerStopping;
use PHPeek\LaravelQueueMetrics\Actions\TransitionWorkerStateAction;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;

/**
 * Listen for worker stopping events.
 * Tracks worker shutdown for uptime and stability metrics.
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
        // - Graceful vs forced shutdown detection
        // - Worker stability metrics
        $this->transitionWorkerState->execute(
            workerId: $workerId,
            newState: WorkerState::STOPPED,
            reason: $event->status === 0 ? 'graceful_shutdown' : 'forced_shutdown',
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
