<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Illuminate\Foundation\Events\Dispatchable;

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
