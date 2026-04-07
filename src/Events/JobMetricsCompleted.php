<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired per job when metrics are captured at job completion.
 * Provides per-job CPU time, memory, and duration from process-level instrumentation.
 */
final class JobMetricsCompleted
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
        public readonly ?string $hostname = null,
        public readonly ?float $workerMemoryLimitMb = null,
    ) {}
}
