<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Database-based implementation of worker heartbeat repository.
 *
 * Replaces the Redis Lua script with DB::transaction for atomic heartbeat updates.
 */
final readonly class DatabaseWorkerHeartbeatRepository implements WorkerHeartbeatRepository
{
    public function __construct(
        private DatabaseMetricsStore $store,
    ) {}

    public function recordHeartbeat(
        string $workerId,
        string $connection,
        string $queue,
        WorkerState $state,
        string|int|null $currentJobId,
        ?string $currentJobClass,
        int $pid,
        string $hostname,
        float $memoryUsageMb = 0.0,
        float $cpuUsagePercent = 0.0,
    ): void {
        DB::transaction(function () use ($workerId, $connection, $queue, $state, $currentJobId, $currentJobClass, $pid, $hostname, $memoryUsageMb, $cpuUsagePercent) {
            $key = $this->store->key('worker', $workerId);
            $existing = $this->store->getHash($key);

            $now = Carbon::now();
            $previousState = $existing['state'] ?? null;
            $lastHeartbeat = isset($existing['last_heartbeat']) ? (int) $existing['last_heartbeat'] : $now->timestamp;
            $idleTime = (float) ($existing['idle_time_seconds'] ?? 0.0);
            $busyTime = (float) ($existing['busy_time_seconds'] ?? 0.0);
            $jobsProcessed = (int) ($existing['jobs_processed'] ?? 0);
            $previousPeakMemory = (float) ($existing['peak_memory_usage_mb'] ?? 0.0);
            $lastStateChange = isset($existing['last_state_change']) ? (int) $existing['last_state_change'] : $now->timestamp;

            // Time since last heartbeat
            $timeSinceLastHeartbeat = $now->timestamp - $lastHeartbeat;

            // Update time in previous state
            if ($previousState === 'idle') {
                $idleTime += $timeSinceLastHeartbeat;
            } elseif ($previousState === 'busy') {
                $busyTime += $timeSinceLastHeartbeat;
            }

            // Increment jobs_processed on busy→idle transition
            if ($previousState === 'busy' && $state->value === 'idle' && $currentJobId === null) {
                $jobsProcessed++;
            }

            // Update last_state_change if state changed
            if ($previousState !== $state->value) {
                $lastStateChange = $now->timestamp;
            }

            // Track peak memory
            $peakMemory = max($previousPeakMemory, $memoryUsageMb);

            $ttl = $this->store->getTtl('raw');

            $this->store->setHash($key, [
                'worker_id' => $workerId,
                'connection' => $connection,
                'queue' => $queue,
                'state' => $state->value,
                'last_heartbeat' => $now->timestamp,
                'last_state_change' => $lastStateChange,
                'current_job_id' => $currentJobId !== null ? (string) $currentJobId : '',
                'current_job_class' => $currentJobClass ?? '',
                'idle_time_seconds' => $idleTime,
                'busy_time_seconds' => $busyTime,
                'jobs_processed' => $jobsProcessed,
                'pid' => $pid,
                'hostname' => $hostname,
                'memory_usage_mb' => $memoryUsageMb,
                'cpu_usage_percent' => $cpuUsagePercent,
                'peak_memory_usage_mb' => $peakMemory,
            ], $ttl);

            $this->store->addToSortedSet(
                $this->store->key('workers', 'all'),
                [$workerId => $now->timestamp]
            );
        });
    }

    public function transitionState(
        string $workerId,
        WorkerState $newState,
        Carbon $transitionTime,
    ): void {
        $workerKey = $this->store->key('worker', $workerId);
        $ttl = $this->store->getTtl('raw');

        // Check if worker exists
        $existing = $this->store->getHash($workerKey);
        if (empty($existing)) {
            return;
        }

        $this->store->setHash($workerKey, [
            'state' => $newState->value,
            'last_state_change' => $transitionTime->timestamp,
        ]);

        // Refresh TTL on state transition
        $this->store->expire($workerKey, $ttl);
    }

    public function getWorker(string $workerId): ?WorkerHeartbeat
    {
        $workerKey = $this->store->key('worker', $workerId);

        /** @var array<string, string> */
        $data = $this->store->getHash($workerKey);

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
        $indexKey = $this->store->key('workers', 'all');

        // Get all worker IDs
        /** @var array<string> */
        $workerIds = $this->store->getSortedSetByRank($indexKey, 0, -1);

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
        $indexKey = $this->store->key('workers', 'all');

        /** @var array<string> */
        $workerIds = $this->store->getSortedSetByRank($indexKey, 0, -1);

        return collect($workerIds)
            ->map(fn (string $workerId) => $this->getWorker($workerId))
            ->filter(fn (?WorkerHeartbeat $worker) => $worker !== null && $worker->state === $state)
            ->values();
    }

    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        $indexKey = $this->store->key('workers', 'all');

        $cutoff = Carbon::now()->subSeconds($thresholdSeconds)->timestamp;

        // Get workers with stale heartbeats
        /** @var array<string> */
        $staleWorkerIds = $this->store->getSortedSetByScore($indexKey, '-inf', (string) $cutoff);

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
        $workerKey = $this->store->key('worker', $workerId);
        $indexKey = $this->store->key('workers', 'all');

        $this->store->pipeline(function ($pipe) use ($workerKey, $indexKey, $workerId) {
            $pipe->delete($workerKey);
            $pipe->removeFromSortedSet($indexKey, $workerId);
        });
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $indexKey = $this->store->key('workers', 'all');

        $cutoff = Carbon::now()->subSeconds($olderThanSeconds)->timestamp;

        // Get old workers
        /** @var array<string> */
        $oldWorkerIds = $this->store->getSortedSetByScore($indexKey, '-inf', (string) $cutoff);

        foreach ($oldWorkerIds as $workerId) {
            $this->removeWorker($workerId);
        }

        return count($oldWorkerIds);
    }
}
