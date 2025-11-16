<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;

/**
 * Worker heartbeat data with state and timing information.
 */
final readonly class WorkerHeartbeat
{
    public function __construct(
        public string $workerId,
        public string $connection,
        public string $queue,
        public WorkerState $state,
        public Carbon $lastHeartbeat,
        public ?Carbon $lastStateChange,
        public ?string $currentJobId,
        public ?string $currentJobClass,
        public float $idleTimeSeconds,
        public float $busyTimeSeconds,
        public int $jobsProcessed,
        public int $pid,
        public string $hostname,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workerId: $data['worker_id'],
            connection: $data['connection'],
            queue: $data['queue'],
            state: WorkerState::from($data['state']),
            lastHeartbeat: Carbon::parse($data['last_heartbeat']),
            lastStateChange: isset($data['last_state_change'])
                ? Carbon::parse($data['last_state_change'])
                : null,
            currentJobId: $data['current_job_id'] ?? null,
            currentJobClass: $data['current_job_class'] ?? null,
            idleTimeSeconds: (float) ($data['idle_time_seconds'] ?? 0.0),
            busyTimeSeconds: (float) ($data['busy_time_seconds'] ?? 0.0),
            jobsProcessed: (int) ($data['jobs_processed'] ?? 0),
            pid: (int) $data['pid'],
            hostname: $data['hostname'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'state' => $this->state->value,
            'last_heartbeat' => $this->lastHeartbeat->toIso8601String(),
            'last_state_change' => $this->lastStateChange?->toIso8601String(),
            'current_job_id' => $this->currentJobId,
            'current_job_class' => $this->currentJobClass,
            'idle_time_seconds' => $this->idleTimeSeconds,
            'busy_time_seconds' => $this->busyTimeSeconds,
            'jobs_processed' => $this->jobsProcessed,
            'pid' => $this->pid,
            'hostname' => $this->hostname,
        ];
    }

    public function secondsSinceLastHeartbeat(): float
    {
        return Carbon::now()->diffInSeconds($this->lastHeartbeat, true);
    }

    public function isStale(int $thresholdSeconds = 60): bool
    {
        return $this->secondsSinceLastHeartbeat() > $thresholdSeconds;
    }
}
