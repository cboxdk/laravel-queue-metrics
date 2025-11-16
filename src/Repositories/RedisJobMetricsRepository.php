<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Concerns\InteractsWithRedis;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Redis-based implementation of job metrics repository.
 */
final class RedisJobMetricsRepository implements JobMetricsRepository
{
    use InteractsWithRedis;

    public function recordStart(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void {
        $redis = $this->getRedis();
        $metricsKey = $this->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->key('job', $jobId);

        // Increment total queued counter
        $redis->hincrby($metricsKey, 'total_queued', 1);

        // Store job start time
        $redis->hmset($jobKey, [
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $startedAt->timestamp,
        ]);
        $redis->expire($jobKey, $this->getTtl('raw'));
    }

    public function recordCompletion(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        Carbon $completedAt,
    ): void {
        $redis = $this->getRedis();
        $metricsKey = $this->key('jobs', $connection, $queue, $jobClass);
        $durationKey = $this->key('durations', $connection, $queue, $jobClass);
        $memoryKey = $this->key('memory', $connection, $queue, $jobClass);

        // Increment counters atomically
        $redis->pipeline(function ($pipe) use (
            $metricsKey,
            $durationKey,
            $memoryKey,
            $durationMs,
            $memoryMb,
            $completedAt
        ) {
            $pipe->hincrby($metricsKey, 'total_processed', 1);
            $pipe->hincrbyfloat($metricsKey, 'total_duration_ms', $durationMs);
            $pipe->hincrbyfloat($metricsKey, 'total_memory_mb', $memoryMb);
            $pipe->hset($metricsKey, 'last_processed_at', $completedAt->timestamp);

            // Store duration sample (sorted set with timestamp as score)
            $pipe->zadd($durationKey, [$durationMs => $completedAt->timestamp]);
            $pipe->expire($durationKey, $this->getTtl('raw'));

            // Store memory sample
            $pipe->zadd($memoryKey, [$memoryMb => $completedAt->timestamp]);
            $pipe->expire($memoryKey, $this->getTtl('raw'));

            // Keep only recent samples (limit to 10000)
            $pipe->zremrangebyrank($durationKey, 0, -10001);
            $pipe->zremrangebyrank($memoryKey, 0, -10001);
        });

        // Clean up job tracking key
        $redis->del($this->key('job', $jobId));
    }

    public function recordFailure(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
    ): void {
        $redis = $this->getRedis();
        $metricsKey = $this->key('jobs', $connection, $queue, $jobClass);

        $redis->pipeline(function ($pipe) use ($metricsKey, $exception, $failedAt) {
            $pipe->hincrby($metricsKey, 'total_failed', 1);
            $pipe->hset($metricsKey, 'last_failed_at', $failedAt->timestamp);
            $pipe->hset($metricsKey, 'last_exception', substr($exception, 0, 1000));
        });

        // Clean up job tracking key
        $redis->del($this->key('job', $jobId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(
        string $jobClass,
        string $connection,
        string $queue,
    ): array {
        $key = $this->key('jobs', $connection, $queue, $jobClass);
        $data = $this->hgetall($key);

        return [
            'total_processed' => (int) ($data['total_processed'] ?? 0),
            'total_failed' => (int) ($data['total_failed'] ?? 0),
            'total_duration_ms' => (float) ($data['total_duration_ms'] ?? 0.0),
            'total_memory_mb' => (float) ($data['total_memory_mb'] ?? 0.0),
            'last_processed_at' => isset($data['last_processed_at'])
                ? Carbon::createFromTimestamp((int) $data['last_processed_at'])
                : null,
            'last_failed_at' => isset($data['last_failed_at'])
                ? Carbon::createFromTimestamp((int) $data['last_failed_at'])
                : null,
            'last_exception' => $data['last_exception'] ?? null,
        ];
    }

    /**
     * @return array<int, float>
     */
    public function getDurationSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->key('durations', $connection, $queue, $jobClass);
        $redis = $this->getRedis();

        // Get most recent samples
        /** @var array<string> */
        $samples = $redis->zrevrange($key, 0, $limit - 1);

        return array_map('floatval', $samples);
    }

    /**
     * @return array<int, float>
     */
    public function getMemorySamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->key('memory', $connection, $queue, $jobClass);
        $redis = $this->getRedis();

        /** @var array<string> */
        $samples = $redis->zrevrange($key, 0, $limit - 1);

        return array_map('floatval', $samples);
    }

    public function getThroughput(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): int {
        $key = $this->key('durations', $connection, $queue, $jobClass);
        $redis = $this->getRedis();

        $cutoff = Carbon::now()->subSeconds($windowSeconds)->timestamp;

        // Count samples within time window
        return (int) $redis->zcount($key, (string) $cutoff, '+inf');
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->key('jobs', '*');
        $keys = $this->scanKeys($pattern);
        $deleted = 0;

        foreach ($keys as $key) {
            $lastProcessed = $this->getRedis()->hget($key, 'last_processed_at');

            if ($lastProcessed === null || $lastProcessed === false) {
                continue;
            }

            $age = Carbon::now()->timestamp - (int) $lastProcessed;

            if ($age > $olderThanSeconds) {
                $this->getRedis()->del($key);
                $deleted++;
            }
        }

        return $deleted;
    }
}
