<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Queue depth statistics with job counts and timing.
 */
final readonly class QueueDepthData
{
    /**
     * @param  int  $delayedDueNowJobs  Subset of $delayedJobs whose availability time has
     *                                  already passed. Only a driver that holds delayed jobs
     *                                  in a separate store reports this — on Redis nothing
     *                                  moves them to the ready set until a worker calls pop(),
     *                                  so a non-zero count with no workers running is work
     *                                  that will never start on its own. Drivers where a due
     *                                  job is already pending (database) report zero.
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $pendingJobs,
        public int $reservedJobs,
        public int $delayedJobs,
        public ?Carbon $oldestPendingJobAge,
        public ?Carbon $oldestDelayedJobAge,
        public Carbon $measuredAt,
        public int $delayedDueNowJobs = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $connection = $data['connection'] ?? '';
        $queue = $data['queue'] ?? '';
        $measuredAt = $data['measured_at'] ?? null;

        if (! is_string($connection) || ! is_string($queue)) {
            throw new \InvalidArgumentException('connection and queue must be strings');
        }

        if ($measuredAt === null) {
            throw new \InvalidArgumentException('measured_at is required');
        }

        return new self(
            connection: $connection,
            queue: $queue,
            pendingJobs: is_numeric($data['pending_jobs'] ?? 0) ? (int) ($data['pending_jobs'] ?? 0) : 0,
            reservedJobs: is_numeric($data['reserved_jobs'] ?? 0) ? (int) ($data['reserved_jobs'] ?? 0) : 0,
            delayedJobs: is_numeric($data['delayed_jobs'] ?? 0) ? (int) ($data['delayed_jobs'] ?? 0) : 0,
            oldestPendingJobAge: isset($data['oldest_pending_job_age']) && is_string($data['oldest_pending_job_age'])
                ? Carbon::parse($data['oldest_pending_job_age'])
                : null,
            oldestDelayedJobAge: isset($data['oldest_delayed_job_age']) && is_string($data['oldest_delayed_job_age'])
                ? Carbon::parse($data['oldest_delayed_job_age'])
                : null,
            measuredAt: is_string($measuredAt) ? Carbon::parse($measuredAt) : Carbon::now(),
            delayedDueNowJobs: is_numeric($data['delayed_due_now_jobs'] ?? 0) ? (int) ($data['delayed_due_now_jobs'] ?? 0) : 0,
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
            'delayed_due_now_jobs' => $this->delayedDueNowJobs,
            'oldest_pending_job_age' => $this->oldestPendingJobAge?->toIso8601String(),
            'oldest_delayed_job_age' => $this->oldestDelayedJobAge?->toIso8601String(),
            'measured_at' => $this->measuredAt->toIso8601String(),
        ];
    }

    public function totalJobs(): int
    {
        return $this->pendingJobs + $this->reservedJobs + $this->delayedJobs;
    }

    /**
     * The live-state shape consumed by QueueMetricsRepository::getQueueState()
     * and the metrics merge in QueueMetricsQueryService.
     *
     * @return array{depth: int, pending: int, scheduled: int, reserved: int, delayed_due_now: int, oldest_job_age: int}
     */
    public function toQueueStateArray(): array
    {
        return [
            'depth' => $this->totalJobs(),
            'pending' => $this->pendingJobs,
            'scheduled' => $this->delayedJobs,
            'reserved' => $this->reservedJobs,
            'delayed_due_now' => $this->delayedDueNowJobs,
            'oldest_job_age' => (int) ($this->secondsOldestPendingJob() ?? 0),
        ];
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
