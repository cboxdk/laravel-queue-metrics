<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Collection;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WorkerHeartbeat;
use PHPeek\LaravelQueueMetrics\Enums\WorkerState;
use PHPeek\LaravelQueueMetrics\Exceptions\LuaScriptException;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Redis-based implementation of worker heartbeat repository.
 */
final readonly class RedisWorkerHeartbeatRepository implements WorkerHeartbeatRepository
{
    public function __construct(
        private RedisMetricsStore $redis,
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
        $driver = $this->redis->driver();
        $workerKey = $this->redis->key('worker', $workerId);
        $indexKey = $this->redis->key('workers', 'all');

        $now = Carbon::now();
        $ttl = $this->redis->getTtl('raw');

        // Get Laravel Redis connection
        $laravelConnection = $this->redis->connection();

        // Prepare script arguments
        // Laravel's Redis connection auto-prefixes keys for both eval() and evalsha()
        $keys = [$workerKey, $indexKey];
        $args = [
            $workerId, // ARGV[1]
            $connection, // ARGV[2]
            $queue, // ARGV[3]
            $state->value, // ARGV[4]
            $currentJobId !== null ? (string) $currentJobId : '', // ARGV[5]
            $currentJobClass ?? '', // ARGV[6]
            (string) $pid, // ARGV[7]
            $hostname, // ARGV[8]
            (string) $memoryUsageMb, // ARGV[9]
            (string) $cpuUsagePercent, // ARGV[10]
            (string) $now->timestamp, // ARGV[11]
            (string) $ttl, // ARGV[12]
        ];

        // Execute Lua script with SHA caching for performance
        $this->executeScriptWithCache($laravelConnection, $keys, $args);
    }

    public function transitionState(
        string $workerId,
        WorkerState $newState,
        Carbon $transitionTime,
    ): void {
        $driver = $this->redis->driver();
        $workerKey = $this->redis->key('worker', $workerId);
        $ttl = $this->redis->getTtl('raw');

        // Check if worker exists
        if (! $driver->exists($workerKey)) {
            return;
        }

        $driver->setHash($workerKey, [
            'state' => $newState->value,
            'last_state_change' => $transitionTime->timestamp,
        ]);

        // Refresh TTL on state transition
        $driver->expire($workerKey, $ttl);
    }

    public function getWorker(string $workerId): ?WorkerHeartbeat
    {
        $workerKey = $this->redis->key('worker', $workerId);
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($workerKey) ?: [];

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
        $indexKey = $this->redis->key('workers', 'all');
        $driver = $this->redis->driver();

        // Get all worker IDs
        /** @var array<string> */
        $workerIds = $driver->getSortedSetByRank($indexKey, 0, -1);

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
        $indexKey = $this->redis->key('workers', 'all');
        $driver = $this->redis->driver();

        /** @var array<string> */
        $workerIds = $driver->getSortedSetByRank($indexKey, 0, -1);

        return collect($workerIds)
            ->map(fn (string $workerId) => $this->getWorker($workerId))
            ->filter(fn (?WorkerHeartbeat $worker) => $worker !== null && $worker->state === $state)
            ->values();
    }

    public function detectStaledWorkers(int $thresholdSeconds = 60): int
    {
        $indexKey = $this->redis->key('workers', 'all');
        $driver = $this->redis->driver();

        $cutoff = Carbon::now()->subSeconds($thresholdSeconds)->timestamp;

        // Get workers with stale heartbeats
        /** @var array<string> */
        $staleWorkerIds = $driver->getSortedSetByScore($indexKey, '-inf', (string) $cutoff);

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
        $driver = $this->redis->driver();
        $workerKey = $this->redis->key('worker', $workerId);
        $indexKey = $this->redis->key('workers', 'all');

        $driver->pipeline(function ($pipe) use ($workerKey, $indexKey, $workerId) {
            $pipe->delete($workerKey);
            $pipe->removeFromSortedSet($indexKey, $workerId);
        });
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $indexKey = $this->redis->key('workers', 'all');
        $driver = $this->redis->driver();

        $cutoff = Carbon::now()->subSeconds($olderThanSeconds)->timestamp;

        // Get old workers
        /** @var array<string> */
        $oldWorkerIds = $driver->getSortedSetByScore($indexKey, '-inf', (string) $cutoff);

        foreach ($oldWorkerIds as $workerId) {
            $this->removeWorker($workerId);
        }

        return count($oldWorkerIds);
    }

    /**
     * Execute Lua script with SHA1 caching for optimal performance.
     *
     * Uses EVALSHA when script is cached, falls back to EVAL + SCRIPT LOAD
     * on first call or if script was evicted from Redis cache.
     *
     * @param  array<string>  $keys
     * @param  array<string>  $args
     *
     * @throws LuaScriptException
     */
    private function executeScriptWithCache(Connection $connection, array $keys, array $args): void
    {
        $scriptPath = __DIR__.'/../Support/LuaScripts/UpdateWorkerHeartbeat.lua';

        $luaScript = file_get_contents($scriptPath);

        if ($luaScript === false) {
            throw LuaScriptException::failedToLoad($scriptPath);
        }

        // IMPORTANT: Laravel's evalsha() does NOT work correctly with automatic key prefixing
        // It returns false instead of executing the script properly
        // We must use eval() which correctly handles prefixing
        /** @phpstan-ignore-next-line argument.type */
        $connection->eval($luaScript, count($keys), ...array_merge($keys, $args));
    }
}
