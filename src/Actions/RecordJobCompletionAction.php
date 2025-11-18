<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\HookManager;

/**
 * Record when a job completes successfully.
 */
final readonly class RecordJobCompletionAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private HookManager $hookManager,
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

        // Prepare data for hooks
        $data = [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'duration_ms' => $durationMs,
            'memory_mb' => $memoryMb,
            'cpu_time_ms' => $cpuTimeMs,
            'hostname' => $hostname,
            'completed_at' => Carbon::now(),
        ];

        // Execute before_record hooks
        $data = $this->hookManager->execute('before_record', $data);
        /** @var array<string, mixed> $data */
        $this->repository->recordCompletion(
            jobId: $data['job_id'],
            jobClass: $data['job_class'],
            connection: $data['connection'],
            queue: $data['queue'],
            durationMs: $data['duration_ms'],
            memoryMb: $data['memory_mb'],
            cpuTimeMs: $data['cpu_time_ms'],
            completedAt: $data['completed_at'],
            hostname: $data['hostname'],
        );

        // Execute after_record hooks
        $this->hookManager->execute('after_record', $data);
    }
}
