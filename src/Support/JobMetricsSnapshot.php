<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Immutable DTO containing collected metrics for a completed/failed job.
 *
 * @internal
 */
final readonly class JobMetricsSnapshot
{
    public function __construct(
        public float $durationMs,
        public float $memoryMb,
        public float $memoryIncrementalMb,
        public float $cpuTimeMs,
    ) {}
}
