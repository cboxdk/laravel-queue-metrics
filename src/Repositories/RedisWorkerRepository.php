<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerStatsData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use PHPeek\LaravelQueueMetrics\Storage\StorageManager;

/**
 * Redis-based implementation of worker repository.
 */
final readonly class RedisWorkerRepository implements WorkerRepository
{
    public function __construct(
        private StorageManager $redis,
    ) {}

    public function registerWorker(
        int $pid,
        string $hostname,
        string $connection,
        string $queue,
        Carbon $spawnedAt,
    ): void {
        $key = $this->redis->key('worker', (string) $pid);
        $redis = $this->redis->getConnection();

        $redis->hmset($key, [
            'pid' => $pid,
            'hostname' => $hostname,
            'connection' => $connection,
            'queue' => $queue,
            'status' => 'idle',
            'jobs_processed' => 0,
            'spawned_at' => $spawnedAt->timestamp,
            'last_activity' => Carbon::now()->timestamp,
        ]);

        // Add to active workers set
        $redis->sadd($this->redis->key('active_workers'), [(string) $pid]);
    }

    public function updateWorkerActivity(
        int $pid,
        string $status,
        ?string $currentJob = null,
        int $jobsProcessed = 0,
        float $idlePercentage = 0.0,
    ): void {
        $key = $this->redis->key('worker', (string) $pid);
        $redis = $this->redis->getConnection();

        $updates = [
            'status' => $status,
            'last_activity' => Carbon::now()->timestamp,
        ];

        if ($currentJob !== null) {
            $updates['current_job'] = $currentJob;
        }

        if ($jobsProcessed > 0) {
            $redis->hincrby($key, 'jobs_processed', $jobsProcessed);
        }

        if ($idlePercentage > 0.0) {
            $updates['idle_percentage'] = (string) $idlePercentage;
        }

        $redis->hmset($key, $updates);
    }

    public function unregisterWorker(int $pid): void
    {
        $key = $this->redis->key('worker', (string) $pid);
        $redis = $this->redis->getConnection();

        $redis->del($key);
        $redis->srem($this->redis->key('active_workers'), [(string) $pid]);
    }

    public function getWorkerStats(int $pid): ?WorkerStatsData
    {
        $key = $this->redis->key('worker', (string) $pid);
        $redis = $this->redis->getConnection();

        /** @var array<string, string> */
        $data = $redis->hgetall($key) ?: [];

        if (empty($data)) {
            return null;
        }

        return WorkerStatsData::fromArray([
            'pid' => (int) $data['pid'],
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
        ]);
    }

    /**
     * @return array<int, WorkerStatsData>
     */
    public function getActiveWorkers(?string $connection = null, ?string $queue = null): array
    {
        $redis = $this->redis->getConnection();

        /** @var array<string> */
        $pids = $redis->smembers($this->redis->key('active_workers'));

        $workers = [];
        foreach ($pids as $pid) {
            $stats = $this->getWorkerStats((int) $pid);

            if ($stats === null) {
                continue;
            }

            // Filter by connection/queue if specified
            if ($connection !== null && $stats->connection !== $connection) {
                continue;
            }

            if ($queue !== null && $stats->queue !== $queue) {
                continue;
            }

            $workers[] = $stats;
        }

        return $workers;
    }

    public function countActiveWorkers(string $connection, string $queue): int
    {
        $workers = $this->getActiveWorkers($connection, $queue);

        return count($workers);
    }

    public function cleanupStaleWorkers(int $olderThanSeconds): int
    {
        $redis = $this->redis->getConnection();

        /** @var array<string> */
        $pids = $redis->smembers($this->redis->key('active_workers'));
        $deleted = 0;

        foreach ($pids as $pid) {
            $key = $this->redis->key('worker', $pid);
            $lastActivity = $redis->hget($key, 'last_activity');

            if ($lastActivity === null || $lastActivity === false) {
                $this->unregisterWorker((int) $pid);
                $deleted++;
                continue;
            }

            $age = Carbon::now()->timestamp - (int) $lastActivity;

            if ($age > $olderThanSeconds) {
                $this->unregisterWorker((int) $pid);
                $deleted++;
            }
        }

        return $deleted;
    }
}
