<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Aggregated metrics for a job class across all queues.
 */
final readonly class AggregatedJobMetricsData
{
    /**
     * @param  array<array{connection: string, queue: string, executions: int, avg_duration_ms: float, avg_memory_mb: float, failures: int, failure_rate: float, throughput_per_minute: float}>  $byQueue
     */
    public function __construct(
        public string $jobClass,
        public int $totalExecutions,
        public int $totalFailures,
        public float $avgDurationMs,
        public float $avgMemoryMb,
        public float $failureRate,
        public float $throughputPerMinute,
        public array $byQueue,
        public Carbon $calculatedAt,
    ) {}

    /**
     * @param  array{job_class: string, total_executions: int, total_failures: int, avg_duration_ms: float, avg_memory_mb: float, failure_rate: float, throughput_per_minute: float, by_queue: array<array{connection: string, queue: string, executions: int, avg_duration_ms: float, avg_memory_mb: float, failures: int, failure_rate: float, throughput_per_minute: float}>, calculated_at: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            jobClass: $data['job_class'],
            totalExecutions: $data['total_executions'],
            totalFailures: $data['total_failures'],
            avgDurationMs: $data['avg_duration_ms'],
            avgMemoryMb: $data['avg_memory_mb'],
            failureRate: $data['failure_rate'],
            throughputPerMinute: $data['throughput_per_minute'],
            byQueue: $data['by_queue'],
            calculatedAt: Carbon::parse($data['calculated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_class' => $this->jobClass,
            'total_executions' => $this->totalExecutions,
            'total_failures' => $this->totalFailures,
            'avg_duration_ms' => round($this->avgDurationMs, 2),
            'avg_memory_mb' => round($this->avgMemoryMb, 2),
            'failure_rate' => round($this->failureRate, 2),
            'throughput_per_minute' => round($this->throughputPerMinute, 2),
            'by_queue' => $this->byQueue,
            'calculated_at' => $this->calculatedAt->toIso8601String(),
        ];
    }

    public function hasFailures(): bool
    {
        return $this->totalFailures > 0;
    }

    public function isHealthy(): bool
    {
        return $this->failureRate < 5.0;
    }
}
