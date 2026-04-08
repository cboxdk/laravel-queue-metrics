<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

/**
 * Database-based implementation of worker repository.
 */
final readonly class DatabaseWorkerRepository implements WorkerRepository
{
    public function __construct(
        private DatabaseMetricsStore $store,
    ) {}

    public function registerWorker(
        int $pid,
        string $hostname,
        string $connection,
        string $queue,
        Carbon $spawnedAt,
    ): void {
        $key = $this->store->key('worker', $hostname, (string) $pid);
        $driver = $this->store->driver();
        $ttl = $this->store->getTtl('raw');

        $driver->setHash($key, [
            'pid' => $pid,
            'hostname' => $hostname,
            'connection' => $connection,
            'queue' => $queue,
            'status' => 'idle',
            'jobs_processed' => 0,
            'spawned_at' => $spawnedAt->timestamp,
            'last_activity' => Carbon::now()->timestamp,
        ]);

        // Add to active workers set with hostname:pid format
        $activeWorkersKey = $this->store->key('active_workers');
        $driver->addToSet($activeWorkersKey, [$hostname.':'.$pid]);

        // Add to workers:all sorted set (score = spawned_at timestamp)
        $indexKey = $this->store->key('workers', 'all');
        $driver->addToSortedSet($indexKey, [$hostname.':'.$pid => $spawnedAt->timestamp]);

        // Set TTL on keys
        $driver->expire($key, $ttl);
        $driver->expire($activeWorkersKey, $ttl);
    }

    public function updateWorkerActivity(
        int $pid,
        string $hostname,
        string $status,
        ?string $currentJob = null,
        int $jobsProcessed = 0,
        float $idlePercentage = 0.0,
    ): void {
        $key = $this->store->key('worker', $hostname, (string) $pid);
        $driver = $this->store->driver();
        $ttl = $this->store->getTtl('raw');

        $updates = [
            'status' => $status,
            'last_activity' => Carbon::now()->timestamp,
        ];

        if ($currentJob !== null) {
            $updates['current_job'] = $currentJob;
        }

        if ($jobsProcessed > 0) {
            $driver->incrementHashField($key, 'jobs_processed', $jobsProcessed);
        }

        if ($idlePercentage > 0.0) {
            $updates['idle_percentage'] = (string) $idlePercentage;
        }

        $driver->setHash($key, $updates);

        // Update the sorted set score to current time for activity tracking
        $indexKey = $this->store->key('workers', 'all');
        $driver->addToSortedSet($indexKey, [$hostname.':'.$pid => Carbon::now()->timestamp]);

        // Refresh TTL on update
        $driver->expire($key, $ttl);
    }

    public function unregisterWorker(int $pid, string $hostname): void
    {
        $key = $this->store->key('worker', $hostname, (string) $pid);
        $driver = $this->store->driver();

        $driver->delete($key);
        $driver->removeFromSet($this->store->key('active_workers'), [$hostname.':'.$pid]);
        $driver->removeFromSortedSet($this->store->key('workers', 'all'), $hostname.':'.$pid);
    }

    public function getWorkerStats(int $pid, string $hostname): ?WorkerStatsData
    {
        $key = $this->store->key('worker', $hostname, (string) $pid);
        $data = $this->store->driver()->getHash($key);

        if (empty($data)) {
            return null;
        }

        return $this->buildWorkerStats($data);
    }

    /**
     * @return array<int, WorkerStatsData>
     */
    public function getActiveWorkers(?string $connection = null, ?string $queue = null): array
    {
        $driver = $this->store->driver();
        $activeWorkersKey = $this->store->key('active_workers');

        /** @var array<int, string> $members */
        $members = $driver->getSetMembers($activeWorkersKey);

        $workers = [];
        foreach ($members as $member) {
            $parts = explode(':', $member, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$memberHostname, $memberPid] = $parts;
            $workerKey = $this->store->key('worker', $memberHostname, $memberPid);

            /** @var array<string, mixed> $data */
            $data = $driver->getHash($workerKey);

            if (empty($data)) {
                continue;
            }

            // Skip workers that are stopped, crashed, or unknown
            $status = $data['status'] ?? 'unknown';
            if (! in_array($status, ['idle', 'busy', 'paused'], true)) {
                continue;
            }

            // Skip sync workers - they are not real persistent workers
            $workerConnection = $data['connection'] ?? 'unknown';
            if ($workerConnection === 'sync') {
                continue;
            }

            $stats = $this->buildWorkerStats($data);

            // Filter by connection if specified
            if ($connection !== null && $stats->connection !== $connection) {
                continue;
            }

            // Filter by queue if specified
            if ($queue !== null && $stats->queue !== $queue) {
                continue;
            }

            $workers[] = $stats;
        }

        return $workers;
    }

    public function countActiveWorkers(string $connection, string $queue): int
    {
        return count($this->getActiveWorkers($connection, $queue));
    }

    public function cleanupStaleWorkers(int $olderThanSeconds): int
    {
        $driver = $this->store->driver();
        $indexKey = $this->store->key('workers', 'all');

        // Calculate cutoff timestamp
        $cutoff = Carbon::now()->subSeconds($olderThanSeconds)->timestamp;

        // Get workers with stale timestamps using sorted set score
        /** @var array<int, string> $staleMembers */
        $staleMembers = $driver->getSortedSetByScore($indexKey, '-inf', (string) $cutoff);

        $deleted = 0;

        foreach ($staleMembers as $member) {
            $parts = explode(':', $member, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$memberHostname, $memberPid] = $parts;
            $workerKey = $this->store->key('worker', $memberHostname, $memberPid);

            // Delete worker hash, remove from index and active set
            $driver->delete($workerKey);
            $driver->removeFromSortedSet($indexKey, $member);
            $driver->removeFromSet($this->store->key('active_workers'), [$member]);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Build a WorkerStatsData from raw hash data.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildWorkerStats(array $data): WorkerStatsData
    {
        return WorkerStatsData::fromArray([
            'pid' => (int) ($data['pid'] ?? 0),
            'hostname' => $data['hostname'] ?? 'unknown',
            'connection' => $data['connection'] ?? 'default',
            'queue' => $data['queue'] ?? 'default',
            'status' => $data['status'] ?? 'idle',
            'jobs_processed' => (int) ($data['jobs_processed'] ?? 0),
            'current_job' => $data['current_job'] ?? null,
            'idle_percentage' => (float) ($data['idle_percentage'] ?? 0.0),
            'spawned_at' => isset($data['spawned_at'])
                ? Carbon::createFromTimestamp((int) $data['spawned_at'])->toIso8601String()
                : null,
            'last_heartbeat' => $data['last_heartbeat'] ?? null,
            'is_horizon_worker' => (bool) ($data['is_horizon_worker'] ?? false),
        ]);
    }
}
