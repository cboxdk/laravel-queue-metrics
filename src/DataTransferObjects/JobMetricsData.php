<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Comprehensive metrics for a specific job class.
 */
final readonly class JobMetricsData
{
    /**
     * @param  array<WindowStats>  $windowStats
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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $jobClass = $data['job_class'] ?? '';
        $connection = $data['connection'] ?? 'default';
        $queue = $data['queue'] ?? 'default';
        $execution = $data['execution'] ?? [];
        $duration = $data['duration'] ?? [];
        $memory = $data['memory'] ?? [];
        $throughput = $data['throughput'] ?? [];
        $failures = $data['failures'] ?? [];
        $windowStatsData = $data['window_stats'] ?? [];
        $calculatedAt = $data['calculated_at'] ?? null;

        $windowStats = is_array($windowStatsData) ? array_map(
            fn (mixed $window): WindowStats => WindowStats::fromArray(is_array($window) ? $window : []),
            $windowStatsData
        ) : [];

        return new self(
            jobClass: is_string($jobClass) ? $jobClass : '',
            connection: is_string($connection) ? $connection : 'default',
            queue: is_string($queue) ? $queue : 'default',
            execution: JobExecutionData::fromArray(is_array($execution) ? $execution : []),
            duration: DurationStats::fromArray(is_array($duration) ? $duration : []),
            memory: MemoryStats::fromArray(is_array($memory) ? $memory : []),
            throughput: ThroughputStats::fromArray(is_array($throughput) ? $throughput : []),
            failures: FailureInfo::fromArray(is_array($failures) ? $failures : []),
            windowStats: $windowStats,
            calculatedAt: (is_string($calculatedAt) || $calculatedAt instanceof \DateTimeInterface)
                ? Carbon::parse($calculatedAt)
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
