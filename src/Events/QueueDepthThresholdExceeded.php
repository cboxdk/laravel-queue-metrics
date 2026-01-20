<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;

/**
 * Fired when queue depth exceeds configured threshold.
 * Critical for autoscaler to trigger scale-up decisions.
 */
final class QueueDepthThresholdExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly QueueDepthData $depth,
        public readonly int $threshold,
        public readonly float $percentageOver,
    ) {}
}
