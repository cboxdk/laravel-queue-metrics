<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Statistics for an individual queue worker.
 */
final readonly class WorkerStatsData
{
    public function __construct(
        public int $pid,
        public string $hostname,
        public string $connection,
        public string $queue,
        public string $status,
        public int $jobsProcessed,
        public ?string $currentJob,
        public float $idlePercentage,
        public Carbon $spawnedAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pid: (int) ($data['pid'] ?? 0),
            hostname: (string) ($data['hostname'] ?? gethostname() ?: 'unknown'),
            connection: (string) ($data['connection'] ?? 'default'),
            queue: (string) ($data['queue'] ?? 'default'),
            status: (string) ($data['status'] ?? 'idle'),
            jobsProcessed: (int) ($data['jobs_processed'] ?? 0),
            currentJob: $data['current_job'] ?? null,
            idlePercentage: (float) ($data['idle_percentage'] ?? 0.0),
            spawnedAt: isset($data['spawned_at'])
                ? Carbon::parse($data['spawned_at'])
                : Carbon::now(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pid' => $this->pid,
            'hostname' => $this->hostname,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'status' => $this->status,
            'jobs_processed' => $this->jobsProcessed,
            'current_job' => $this->currentJob,
            'idle_percentage' => $this->idlePercentage,
            'spawned_at' => $this->spawnedAt->toIso8601String(),
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active' || $this->currentJob !== null;
    }

    public function isIdle(): bool
    {
        return $this->status === 'idle' && $this->currentJob === null;
    }

    public function getUptimeSeconds(): int
    {
        return (int) Carbon::now()->diffInSeconds($this->spawnedAt);
    }
}
