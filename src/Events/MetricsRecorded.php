<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Cbox\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;

/**
 * Fired when new metrics are recorded.
 * High-frequency event for real-time monitoring.
 */
final class MetricsRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly JobMetricsData $metrics,
    ) {}
}
