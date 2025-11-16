<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Queue depth statistics with job counts and timing.
 */
final readonly class QueueDepthData
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $pendingJobs,
        public int $reservedJobs,
        public int $delayedJobs,
        public ?Carbon $oldestPendingJobAge,
        public ?Carbon $oldestDelayedJobAge,
        public Carbon $measuredAt,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: $data['connection'],
            queue: $data['queue'],
            pendingJobs: (int) ($data['pending_jobs'] ?? 0),
            reservedJobs: (int) ($data['reserved_jobs'] ?? 0),
            delayedJobs: (int) ($data['delayed_jobs'] ?? 0),
            oldestPendingJobAge: isset($data['oldest_pending_job_age'])
                ? Carbon::parse($data['oldest_pending_job_age'])
                : null,
            oldestDelayedJobAge: isset($data['oldest_delayed_job_age'])
                ? Carbon::parse($data['oldest_delayed_job_age'])
                : null,
            measuredAt: Carbon::parse($data['measured_at']),
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
            'pending_jobs' => $this->pendingJobs,
            'reserved_jobs' => $this->reservedJobs,
            'delayed_jobs' => $this->delayedJobs,
            'oldest_pending_job_age' => $this->oldestPendingJobAge?->toIso8601String(),
            'oldest_delayed_job_age' => $this->oldestDelayedJobAge?->toIso8601String(),
            'measured_at' => $this->measuredAt->toIso8601String(),
        ];
    }

    public function totalJobs(): int
    {
        return $this->pendingJobs + $this->reservedJobs + $this->delayedJobs;
    }

    public function secondsOldestPendingJob(): ?float
    {
        if ($this->oldestPendingJobAge === null) {
            return null;
        }

        return Carbon::now()->diffInSeconds($this->oldestPendingJobAge, true);
    }

    public function hasBacklog(): bool
    {
        return $this->pendingJobs > 0;
    }

    public function isEmpty(): bool
    {
        return $this->totalJobs() === 0;
    }
}
