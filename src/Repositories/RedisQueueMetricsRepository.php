<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Redis-based implementation of queue metrics repository.
 */
final readonly class RedisQueueMetricsRepository implements QueueMetricsRepository
{
    public function __construct(
        private RedisMetricsStore $redis,
    ) {}

    /**
     * @return array{depth: int, pending: int, scheduled: int, reserved: int, oldest_job_age: int}
     */
    public function getQueueState(string $connection, string $queue): array
    {
        $queueManager = Queue::connection($connection);

        // Get queue size (pending jobs)
        $size = method_exists($queueManager, 'size')
            ? $queueManager->size($queue)
            : 0;

        // For detailed metrics, we'd need to query the queue backend directly
        // This is a simplified version - extend based on your queue driver
        return [
            'depth' => $size,
            'pending' => $size,
            'scheduled' => 0,
            'reserved' => 0,
            'oldest_job_age' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function recordSnapshot(
        string $connection,
        string $queue,
        array $metrics,
    ): void {
        $key = $this->redis->key('queue_snapshot', $connection, $queue);
        $timestampKey = $this->redis->key('queue_snapshots', $connection, $queue);

        $driver = $this->redis->driver();
        $now = Carbon::now();
        $ttl = $this->redis->getTtl('aggregated');

        // Store latest snapshot
        $driver->setHash($key, array_merge($metrics, [
            'recorded_at' => $now->timestamp,
        ]), $ttl);

        // Add to time-series (sorted set)
        $driver->addToSortedSet($timestampKey, [
            json_encode($metrics, JSON_THROW_ON_ERROR) => $now->timestamp,
        ], $ttl);

        // Keep only recent snapshots (last 1000)
        $driver->removeSortedSetByRank($timestampKey, 0, -1001);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestMetrics(string $connection, string $queue): array
    {
        $key = $this->redis->key('queue_snapshot', $connection, $queue);
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

        if (empty($data)) {
            return [];
        }

        return [
            'depth' => (int) ($data['depth'] ?? 0),
            'pending' => (int) ($data['pending'] ?? 0),
            'scheduled' => (int) ($data['scheduled'] ?? 0),
            'reserved' => (int) ($data['reserved'] ?? 0),
            'oldest_job_age' => (int) ($data['oldest_job_age'] ?? 0),
            'throughput_per_minute' => (float) ($data['throughput_per_minute'] ?? 0.0),
            'avg_duration' => (float) ($data['avg_duration'] ?? 0.0),
            'failure_rate' => (float) ($data['failure_rate'] ?? 0.0),
            'utilization_rate' => (float) ($data['utilization_rate'] ?? 0.0),
            'active_workers' => (int) ($data['active_workers'] ?? 0),
            'recorded_at' => isset($data['recorded_at'])
                ? Carbon::createFromTimestamp((int) $data['recorded_at'])
                : null,
        ];
    }

    /**
     * @return array{status: string, score: float}
     */
    public function getHealthStatus(string $connection, string $queue): array
    {
        $metrics = $this->getLatestMetrics($connection, $queue);

        if (empty($metrics)) {
            return ['status' => 'unknown', 'score' => 0.0];
        }

        $score = $this->calculateHealthScore($metrics);

        $status = match (true) {
            $score >= 80.0 => 'healthy',
            $score >= 50.0 => 'warning',
            default => 'critical',
        };

        return ['status' => $status, 'score' => $score];
    }

    /**
     * @return array<int, array{connection: string, queue: string}>
     */
    public function listQueues(): array
    {
        $pattern = $this->redis->key('discovered', '*', '*');
        $keys = $this->scanKeys($pattern);

        $queues = [];
        foreach ($keys as $key) {
            // Extract connection and queue from key
            $parts = explode(':', $key);
            if (count($parts) >= 4) {
                $queues[] = [
                    'connection' => $parts[2],
                    'queue' => $parts[3],
                ];
            }
        }

        return $queues;
    }

    public function markQueueDiscovered(string $connection, string $queue): void
    {
        $key = $this->redis->key('discovered', $connection, $queue);
        $this->redis->driver()->set($key, Carbon::now()->timestamp);
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->redis->key('queue_snapshot', '*', '*');
        $keys = $this->scanKeys($pattern);
        $driver = $this->redis->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $recordedAt = $driver->getHashField($key, 'recorded_at');

            if ($recordedAt === null || $recordedAt === false) {
                continue;
            }

            $recordedAtInt = is_numeric($recordedAt) ? (int) $recordedAt : 0;
            $age = Carbon::now()->timestamp - $recordedAtInt;

            if ($age > $olderThanSeconds) {
                $driver->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function calculateHealthScore(array $metrics): float
    {
        $score = 100.0;

        // Penalize for queue depth
        $depthValue = $metrics['depth'] ?? 0;
        $depth = is_numeric($depthValue) ? (int) $depthValue : 0;
        if ($depth > 100) {
            $score -= min(30, ($depth - 100) / 10);
        }

        // Penalize for old jobs
        $oldestAgeValue = $metrics['oldest_job_age'] ?? 0;
        $oldestAge = is_numeric($oldestAgeValue) ? (int) $oldestAgeValue : 0;
        if ($oldestAge > 300) { // 5 minutes
            $score -= min(30, ($oldestAge - 300) / 60);
        }

        // Penalize for high failure rate
        $failureRateValue = $metrics['failure_rate'] ?? 0.0;
        $failureRate = is_numeric($failureRateValue) ? (float) $failureRateValue : 0.0;
        $score -= min(20, $failureRate);

        // Penalize for no active workers
        $activeWorkersValue = $metrics['active_workers'] ?? 0;
        $activeWorkers = is_numeric($activeWorkersValue) ? (int) $activeWorkersValue : 0;
        if ($activeWorkers === 0 && $depth > 0) {
            $score -= 20;
        }

        return max(0.0, $score);
    }

    /**
     * @return array<string>
     */
    private function scanKeys(string $pattern): array
    {
        return $this->redis->driver()->scanKeys($pattern);
    }
}
