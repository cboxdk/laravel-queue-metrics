<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when baseline metrics are recalculated.
 * Provides updated baseline for autoscaler vertical scaling decisions.
 */
final class BaselineRecalculated
{
    use Dispatchable;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly BaselineData $baseline,
        public readonly bool $significantChange,
    ) {}
}
