<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Repositories\Concerns\InteractsWithRedis;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;

/**
 * Redis-based implementation of worker heartbeat repository.
 */
final class RedisWorkerHeartbeatRepository implements WorkerHeartbeatRepository
{
    use InteractsWithRedis;

    public function recordHeartbeat(
        string $workerId,
        string $connection,
        string $queue,
        WorkerState $state,
        ?string $currentJobId,
        ?string $currentJobClass,
        int $pid,
        string $hostname,
    ): void {
        $redis = $this->getRedis();
        $workerKey = $this->key('worker', $workerId);
        $indexKey = $this->key('workers', 'all');

        $now = Carbon::now();

        // Get existing data to calculate state durations
        $existingData = $this->hgetall($workerKey);
        $previousState = isset($existingData['state'])
            ? WorkerState::from($existingData['state'])
            : null;

        $idleTime = (float) ($existingData['idle_time_seconds'] ?? 0.0);
        $busyTime = (float) ($existingData['busy_time_seconds'] ?? 0.0);
        $lastHeartbeat = isset($existingData['last_heartbeat'])
            ? Carbon::createFromTimestamp((int) $existingData['last_heartbeat'])
            : $now;

        // Calculate time since last heartbeat
        $timeSinceLastHeartbeat = $now->diffInSeconds($lastHeartbeat, true);

        // Update time in previous state
        if ($previousState === WorkerState::IDLE) {
            $idleTime += $timeSinceLastHeartbeat;
        } elseif ($previousState === WorkerState::BUSY) {
            $busyTime += $timeSinceLastHeartbeat;
        }

        $jobsProcessed = (int) ($existingData['jobs_processed'] ?? 0);

        // If transitioning from BUSY to IDLE and we have a job, increment counter
        if ($previousState === WorkerState::BUSY && $state === WorkerState::IDLE && $currentJobId === null) {
            $jobsProcessed++;
        }

        $lastStateChange = $previousState !== $state
            ? $now->timestamp
            : (int) ($existingData['last_state_change'] ?? $now->timestamp);

        // Store worker data
        $redis->pipeline(function ($pipe) use (
            $workerKey,
            $indexKey,
            $workerId,
            $connection,
            $queue,
            $state,
            $currentJobId,
            $currentJobClass,
            $idleTime,
            $busyTime,
            $jobsProcessed,
            $pid,
            $hostname,
            $now,
            $lastStateChange
        ) {
            $pipe->hmset($workerKey, [
                'worker_id' => $workerId,
                'connection' => $connection,
                'queue' => $queue,
                'state' => $state->value,
                'last_heartbeat' => $now->timestamp,
                'last_state_change' => $lastStateChange,
                'current_job_id' => $currentJobId ?? '',
                'current_job_class' => $currentJobClass ?? '',
                'idle_time_seconds' => $idleTime,
                'busy_time_seconds' => $busyTime,
                'jobs_processed' => $jobsProcessed,
                'pid' => $pid,
                'hostname' => $hostname,
            ]);

            // Add to index with heartbeat as score for easy stale detection
            $pipe->zadd($indexKey, [$workerId => $now->timestamp]);

            // Set TTL
            $pipe->expire($workerKey, $this->getTtl('raw'));
            $pipe->expire($indexKey, $this->getTtl('raw'));
        });
    }

    public function transitionState(
        string $workerId,
        WorkerState $newState,
        Carbon $transitionTime,
    ): void {
        $redis = $this->getRedis();
        $workerKey = $this->key('worker', $workerId);

        // Check if worker exists
        if (! $redis->exists($workerKey)) {
            return;
        }

        $redis->hmset($workerKey, [
            'state' => $newState->value,
            'last_state_change' => $transitionTime->timestamp,
        ]);
    }

    public function getWorker(string $workerId): ?WorkerHeartbeat
    {
        $workerKey = $this->key('worker', $workerId);
        $data = $this->hgetall($workerKey);

        if (empty($data)) {
            return null;
        }

        return WorkerHeartbeat::fromArray($data);
    }

    /**
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getActiveWorkers(
        ?string $connection = null,
        ?string $queue = null,
    ): Collection {
        $indexKey = $this->key('workers', 'all');
        $redis = $this->getRedis();

        // Get all worker IDs
        /** @var array<string> */
        $workerIds = $redis->zrange($indexKey, 0, -1);

        $workers = collect($workerIds)
            ->map(fn (string $workerId) => $this->getWorker($workerId))
            ->filter(fn (?WorkerHeartbeat $worker) => $worker !== null)
            ->filter(fn (WorkerHeartbeat $worker) => $worker->state->isActive());

        // Filter by connection if specified
        if ($connection !== null) {
            $workers = $workers->filter(
                fn (WorkerHeartbeat $worker) => $worker->connection === $connection
            );
        }

        // Filter by queue if specified
        if ($queue !== null) {
            $workers = $workers->filter(
                fn (WorkerHeartbeat $worker) => $worker->queue === $queue
            );
        }

        return $workers->values();
    }

    /**
     * @return Collection<int, WorkerHeartbeat>
     */
    public function getWorkersByState(WorkerState $state): Collection
    {
        $indexKey = $this->key('workers', 'all');
        $redis = $this->getRedis();

        /** @var array<string> */
        $workerIds = $redis->zrange($indexKey, 0, -1);

        return collect($workerIds)
            ->map(fn (string $workerId) => $this->getWorker($workerId))
            ->filter(fn (?WorkerHeartbeat $worker) => $worker !== null && $worker->state === $state)
            ->values();
    }

    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        $indexKey = $this->key('workers', 'all');
        $redis = $this->getRedis();

        $cutoff = Carbon::now()->subSeconds($thresholdSeconds)->timestamp;

        // Get workers with stale heartbeats
        /** @var array<string> */
        $staleWorkerIds = $redis->zrangebyscore($indexKey, '-inf', (string) $cutoff);

        $markedAsCrashed = 0;

        foreach ($staleWorkerIds as $workerId) {
            $worker = $this->getWorker($workerId);

            if ($worker === null) {
                continue;
            }

            // Only mark as crashed if currently active
            if ($worker->state->isActive()) {
                $this->transitionState($workerId, WorkerState::CRASHED, Carbon::now());
                $markedAsCrashed++;
            }
        }

        return $markedAsCrashed;
    }

    public function removeWorker(string $workerId): void
    {
        $redis = $this->getRedis();
        $workerKey = $this->key('worker', $workerId);
        $indexKey = $this->key('workers', 'all');

        $redis->pipeline(function ($pipe) use ($workerKey, $indexKey, $workerId) {
            $pipe->del($workerKey);
            $pipe->zrem($indexKey, $workerId);
        });
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $indexKey = $this->key('workers', 'all');
        $redis = $this->getRedis();

        $cutoff = Carbon::now()->subSeconds($olderThanSeconds)->timestamp;

        // Get old workers
        /** @var array<string> */
        $oldWorkerIds = $redis->zrangebyscore($indexKey, '-inf', (string) $cutoff);

        foreach ($oldWorkerIds as $workerId) {
            $this->removeWorker($workerId);
        }

        return count($oldWorkerIds);
    }
}
