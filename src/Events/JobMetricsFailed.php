<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired per job when metrics are captured at job failure.
 * Provides per-job CPU time, memory, duration, and exception from process-level instrumentation.
 */
final class JobMetricsFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $jobId,
        public readonly string $jobClass,
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $durationMs,
        public readonly float $memoryMb,
        public readonly float $cpuTimeMs,
        public readonly string $exceptionMessage,
        public readonly ?string $hostname = null,
        public readonly ?float $workerMemoryLimitMb = null,
        public readonly float $memoryIncrementalMb = 0.0,
    ) {}
}
