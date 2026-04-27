<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a debounced job is detected and discarded.
 * The job was superseded by a newer dispatch and never executed.
 */
final class JobMetricsDebounced
{
    use Dispatchable;

    public function __construct(
        public readonly string $jobId,
        public readonly string $jobClass,
        public readonly string $connection,
        public readonly string $queue,
        public readonly ?string $hostname = null,
    ) {}
}
