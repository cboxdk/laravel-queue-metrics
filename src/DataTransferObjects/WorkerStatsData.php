<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Statistics for an individual queue worker.
 * Supports both standard Laravel queue workers and Horizon workers.
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
        public ?Carbon $lastHeartbeat = null,
        public bool $isHorizonWorker = false,
        public ?string $supervisorName = null,
        public ?int $parentSupervisorPid = null,
        public ?string $workersPoolName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pid = $data['pid'] ?? 0;
        $hostname = $data['hostname'] ?? gethostname() ?: 'unknown';
        $connection = $data['connection'] ?? 'default';
        $queue = $data['queue'] ?? 'default';
        $status = $data['status'] ?? 'idle';
        $jobsProcessed = $data['jobs_processed'] ?? 0;
        $currentJob = $data['current_job'] ?? null;
        $idlePercentage = $data['idle_percentage'] ?? 0.0;
        $spawnedAt = $data['spawned_at'] ?? null;
        $lastHeartbeat = $data['last_heartbeat'] ?? null;
        $isHorizonWorker = $data['is_horizon_worker'] ?? false;
        $supervisorName = $data['supervisor_name'] ?? null;
        $parentSupervisorPid = $data['parent_supervisor_pid'] ?? null;
        $workersPoolName = $data['workers_pool_name'] ?? null;

        return new self(
            pid: is_numeric($pid) ? (int) $pid : 0,
            hostname: is_string($hostname) ? $hostname : 'unknown',
            connection: is_string($connection) ? $connection : 'default',
            queue: is_string($queue) ? $queue : 'default',
            status: is_string($status) ? $status : 'idle',
            jobsProcessed: is_numeric($jobsProcessed) ? (int) $jobsProcessed : 0,
            currentJob: is_string($currentJob) ? $currentJob : null,
            idlePercentage: is_numeric($idlePercentage) ? (float) $idlePercentage : 0.0,
            spawnedAt: match (true) {
                is_numeric($spawnedAt) => Carbon::createFromTimestamp((int) $spawnedAt),
                is_string($spawnedAt) => Carbon::parse($spawnedAt),
                $spawnedAt instanceof \DateTimeInterface => Carbon::parse($spawnedAt),
                default => Carbon::now(),
            },
            lastHeartbeat: $lastHeartbeat !== null
                ? (is_numeric($lastHeartbeat)
                    ? Carbon::createFromTimestamp((int) $lastHeartbeat)
                    : (is_string($lastHeartbeat) ? Carbon::parse($lastHeartbeat) : null))
                : null,
            isHorizonWorker: (bool) $isHorizonWorker,
            supervisorName: is_string($supervisorName) ? $supervisorName : null,
            parentSupervisorPid: is_numeric($parentSupervisorPid) ? (int) $parentSupervisorPid : null,
            workersPoolName: is_string($workersPoolName) ? $workersPoolName : null,
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
            'last_heartbeat' => $this->lastHeartbeat?->toIso8601String(),
            'is_horizon_worker' => $this->isHorizonWorker,
            'supervisor_name' => $this->supervisorName,
            'parent_supervisor_pid' => $this->parentSupervisorPid,
            'workers_pool_name' => $this->workersPoolName,
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
