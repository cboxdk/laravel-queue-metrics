<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when worker efficiency changes significantly.
 * Used by autoscaler for horizontal scaling decisions.
 */
final class WorkerEfficiencyChanged
{
    use Dispatchable;

    public function __construct(
        public readonly float $currentEfficiency,
        public readonly float $previousEfficiency,
        public readonly float $changePercentage,
        public readonly int $activeWorkers,
        public readonly int $idleWorkers,
    ) {}

    public function getScalingRecommendation(): string
    {
        if ($this->currentEfficiency > 90 && $this->idleWorkers === 0) {
            return 'scale_up';
        }

        if ($this->currentEfficiency < 50 && $this->idleWorkers > $this->activeWorkers * 0.3) {
            return 'scale_down';
        }

        return 'maintain';
    }
}
