<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Record when a job completes successfully.
 */
final readonly class RecordJobCompletionAction
{
    public function __construct(
        private JobMetricsRepository $repository,
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs = 0.0,
        ?string $hostname = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        $this->repository->recordCompletion(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            durationMs: $durationMs,
            memoryMb: $memoryMb,
            cpuTimeMs: $cpuTimeMs,
            completedAt: Carbon::now(),
            hostname: $hostname,
        );
    }
}
