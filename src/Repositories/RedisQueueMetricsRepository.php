<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPeek\LaravelQueueMetrics\Repositories\Concerns\InteractsWithRedis;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

/**
 * Redis-based implementation of queue metrics repository.
 */
final class RedisQueueMetricsRepository implements QueueMetricsRepository
{
    use InteractsWithRedis;

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
        $key = $this->key('queue_snapshot', $connection, $queue);
        $timestampKey = $this->key('queue_snapshots', $connection, $queue);

        $redis = $this->getRedis();
        $now = Carbon::now();

        // Store latest snapshot
        $this->hmset($key, array_merge($metrics, [
            'recorded_at' => $now->timestamp,
        ]), $this->getTtl('aggregated'));

        // Add to time-series (sorted set)
        $redis->zadd($timestampKey, [
            json_encode($metrics, JSON_THROW_ON_ERROR) => $now->timestamp,
        ]);
        $redis->expire($timestampKey, $this->getTtl('aggregated'));

        // Keep only recent snapshots (last 1000)
        $redis->zremrangebyrank($timestampKey, 0, -1001);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestMetrics(string $connection, string $queue): array
    {
        $key = $this->key('queue_snapshot', $connection, $queue);
        $data = $this->hgetall($key);

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
        $pattern = $this->key('discovered', '*', '*');
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
        $key = $this->key('discovered', $connection, $queue);
        $this->getRedis()->set($key, Carbon::now()->timestamp);
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->key('queue_snapshot', '*', '*');
        $keys = $this->scanKeys($pattern);
        $deleted = 0;

        foreach ($keys as $key) {
            $recordedAt = $this->getRedis()->hget($key, 'recorded_at');

            if ($recordedAt === null || $recordedAt === false) {
                continue;
            }

            $age = Carbon::now()->timestamp - (int) $recordedAt;

            if ($age > $olderThanSeconds) {
                $this->getRedis()->del($key);
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
        $depth = (int) ($metrics['depth'] ?? 0);
        if ($depth > 100) {
            $score -= min(30, ($depth - 100) / 10);
        }

        // Penalize for old jobs
        $oldestAge = (int) ($metrics['oldest_job_age'] ?? 0);
        if ($oldestAge > 300) { // 5 minutes
            $score -= min(30, ($oldestAge - 300) / 60);
        }

        // Penalize for high failure rate
        $failureRate = (float) ($metrics['failure_rate'] ?? 0.0);
        $score -= min(20, $failureRate);

        // Penalize for no active workers
        $activeWorkers = (int) ($metrics['active_workers'] ?? 0);
        if ($activeWorkers === 0 && $depth > 0) {
            $score -= 20;
        }

        return max(0.0, $score);
    }
}
