<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Comprehensive metrics for a specific queue.
 */
final readonly class QueueMetricsData
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $depth,
        public int $pending,
        public int $scheduled,
        public int $reserved,
        public int $oldestJobAge,
        public string $ageStatus,
        public float $throughputPerMinute,
        public float $avgDuration,
        public float $failureRate,
        public float $utilizationRate,
        public int $activeWorkers,
        public string $driver,
        public HealthStats $health,
        public Carbon $calculatedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $connection = $data['connection'] ?? 'default';
        $queue = $data['queue'] ?? 'default';
        $ageStatus = $data['age_status'] ?? 'normal';
        $driver = $data['driver'] ?? 'unknown';
        $health = $data['health'] ?? [];
        $calculatedAt = $data['calculated_at'] ?? null;

        return new self(
            connection: is_string($connection) ? $connection : 'default',
            queue: is_string($queue) ? $queue : 'default',
            depth: is_numeric($data['depth'] ?? 0) ? (int) $data['depth'] : 0,
            pending: is_numeric($data['pending'] ?? 0) ? (int) $data['pending'] : 0,
            scheduled: is_numeric($data['scheduled'] ?? 0) ? (int) $data['scheduled'] : 0,
            reserved: is_numeric($data['reserved'] ?? 0) ? (int) $data['reserved'] : 0,
            oldestJobAge: is_numeric($data['oldest_job_age'] ?? 0) ? (int) $data['oldest_job_age'] : 0,
            ageStatus: is_string($ageStatus) ? $ageStatus : 'normal',
            throughputPerMinute: is_numeric($data['throughput_per_minute'] ?? 0.0) ? (float) $data['throughput_per_minute'] : 0.0,
            avgDuration: is_numeric($data['avg_duration'] ?? 0.0) ? (float) $data['avg_duration'] : 0.0,
            failureRate: is_numeric($data['failure_rate'] ?? 0.0) ? (float) $data['failure_rate'] : 0.0,
            utilizationRate: is_numeric($data['utilization_rate'] ?? 0.0) ? (float) $data['utilization_rate'] : 0.0,
            activeWorkers: is_numeric($data['active_workers'] ?? 0) ? (int) $data['active_workers'] : 0,
            driver: is_string($driver) ? $driver : 'unknown',
            health: HealthStats::fromArray(is_array($health) ? $health : []),
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
            'connection' => $this->connection,
            'queue' => $this->queue,
            'depth' => $this->depth,
            'pending' => $this->pending,
            'scheduled' => $this->scheduled,
            'reserved' => $this->reserved,
            'oldest_job_age' => $this->oldestJobAge,
            'age_status' => $this->ageStatus,
            'throughput_per_minute' => $this->throughputPerMinute,
            'avg_duration' => $this->avgDuration,
            'failure_rate' => $this->failureRate,
            'utilization_rate' => $this->utilizationRate,
            'active_workers' => $this->activeWorkers,
            'driver' => $this->driver,
            'health' => $this->health->toArray(),
            'calculated_at' => $this->calculatedAt->toIso8601String(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->depth === 0 && $this->pending === 0 && $this->scheduled === 0;
    }

    public function isBacklogged(): bool
    {
        return $this->depth > 100 || $this->oldestJobAge > 300; // 5 minutes
    }

    public function hasActiveWorkers(): bool
    {
        return $this->activeWorkers > 0;
    }
}
