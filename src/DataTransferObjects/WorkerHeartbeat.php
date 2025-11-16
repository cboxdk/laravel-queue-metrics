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
        public float $memoryUsageMb = 0.0,
        public float $cpuUsagePercent = 0.0,
        public float $peakMemoryUsageMb = 0.0,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $workerId = $data['worker_id'] ?? '';
        $connection = $data['connection'] ?? '';
        $queue = $data['queue'] ?? '';
        $state = $data['state'] ?? 'unknown';
        $lastHeartbeat = $data['last_heartbeat'] ?? null;
        $hostname = $data['hostname'] ?? 'unknown';

        if (! is_string($workerId) || ! is_string($connection) || ! is_string($queue) || ! is_string($hostname)) {
            throw new \InvalidArgumentException('worker_id, connection, queue, and hostname must be strings');
        }

        if (! is_string($state)) {
            $state = 'unknown';
        }

        $currentJobId = $data['current_job_id'] ?? null;
        $currentJobClass = $data['current_job_class'] ?? null;

        return new self(
            workerId: $workerId,
            connection: $connection,
            queue: $queue,
            state: WorkerState::from($state),
            lastHeartbeat: is_string($lastHeartbeat) ? Carbon::parse($lastHeartbeat) : Carbon::now(),
            lastStateChange: isset($data['last_state_change']) && is_string($data['last_state_change'])
                ? Carbon::parse($data['last_state_change'])
                : null,
            currentJobId: is_string($currentJobId) ? $currentJobId : null,
            currentJobClass: is_string($currentJobClass) ? $currentJobClass : null,
            idleTimeSeconds: is_numeric($data['idle_time_seconds'] ?? 0.0) ? (float) ($data['idle_time_seconds'] ?? 0.0) : 0.0,
            busyTimeSeconds: is_numeric($data['busy_time_seconds'] ?? 0.0) ? (float) ($data['busy_time_seconds'] ?? 0.0) : 0.0,
            jobsProcessed: is_numeric($data['jobs_processed'] ?? 0) ? (int) ($data['jobs_processed'] ?? 0) : 0,
            pid: is_numeric($data['pid'] ?? 0) ? (int) ($data['pid'] ?? 0) : 0,
            hostname: $hostname,
            memoryUsageMb: is_numeric($data['memory_usage_mb'] ?? 0.0) ? (float) ($data['memory_usage_mb'] ?? 0.0) : 0.0,
            cpuUsagePercent: is_numeric($data['cpu_usage_percent'] ?? 0.0) ? (float) ($data['cpu_usage_percent'] ?? 0.0) : 0.0,
            peakMemoryUsageMb: is_numeric($data['peak_memory_usage_mb'] ?? 0.0) ? (float) ($data['peak_memory_usage_mb'] ?? 0.0) : 0.0,
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
            'memory_usage_mb' => $this->memoryUsageMb,
            'cpu_usage_percent' => $this->cpuUsagePercent,
            'peak_memory_usage_mb' => $this->peakMemoryUsageMb,
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
