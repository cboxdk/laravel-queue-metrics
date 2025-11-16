<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Comprehensive metrics for a specific job class.
 */
final readonly class JobMetricsData
{
    /**
     * @param array<WindowStats> $windowStats
     */
    public function __construct(
        public string $jobClass,
        public string $connection,
        public string $queue,
        public JobExecutionData $execution,
        public DurationStats $duration,
        public MemoryStats $memory,
        public ThroughputStats $throughput,
        public FailureInfo $failures,
        public array $windowStats,
        public Carbon $calculatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $windowStats = array_map(
            fn (array $window): WindowStats => WindowStats::fromArray($window),
            $data['window_stats'] ?? []
        );

        return new self(
            jobClass: (string) ($data['job_class'] ?? ''),
            connection: (string) ($data['connection'] ?? 'default'),
            queue: (string) ($data['queue'] ?? 'default'),
            execution: JobExecutionData::fromArray($data['execution'] ?? []),
            duration: DurationStats::fromArray($data['duration'] ?? []),
            memory: MemoryStats::fromArray($data['memory'] ?? []),
            throughput: ThroughputStats::fromArray($data['throughput'] ?? []),
            failures: FailureInfo::fromArray($data['failures'] ?? []),
            windowStats: $windowStats,
            calculatedAt: isset($data['calculated_at'])
                ? Carbon::parse($data['calculated_at'])
                : Carbon::now(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_class' => $this->jobClass,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'execution' => $this->execution->toArray(),
            'duration' => $this->duration->toArray(),
            'memory' => $this->memory->toArray(),
            'throughput' => $this->throughput->toArray(),
            'failures' => $this->failures->toArray(),
            'window_stats' => array_map(
                fn (WindowStats $stats): array => $stats->toArray(),
                $this->windowStats
            ),
            'calculated_at' => $this->calculatedAt->toIso8601String(),
        ];
    }

    public function hasFailures(): bool
    {
        return $this->failures->count > 0;
    }

    public function isHealthy(): bool
    {
        return $this->execution->successRate >= 95.0 && $this->failures->rate < 5.0;
    }
}
