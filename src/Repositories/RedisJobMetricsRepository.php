<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Redis\Connections\Connection;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Storage\StorageManager;

/**
 * Redis-based implementation of job metrics repository.
 */
final readonly class RedisJobMetricsRepository implements JobMetricsRepository
{
    public function __construct(
        private StorageManager $redis,
    ) {}

    public function recordStart(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void {
        $redis = $this->redis->getConnection();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->redis->key('job', $jobId);

        // Increment total queued counter
        $redis->hincrby($metricsKey, 'total_queued', 1);

        // Store job start time
        $redis->hmset($jobKey, [
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $startedAt->timestamp,
        ]);
        $redis->expire($jobKey, $this->redis->getTtl('raw'));
    }

    public function recordCompletion(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs,
        Carbon $completedAt,
    ): void {
        $redis = $this->redis->getConnection();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $durationKey = $this->redis->key('durations', $connection, $queue, $jobClass);
        $memoryKey = $this->redis->key('memory', $connection, $queue, $jobClass);
        $cpuKey = $this->redis->key('cpu', $connection, $queue, $jobClass);

        // Increment counters atomically
        $redis->pipeline(function ($pipe) use (
            $metricsKey,
            $durationKey,
            $memoryKey,
            $cpuKey,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $completedAt
        ) {
            $pipe->hincrby($metricsKey, 'total_processed', 1);
            $pipe->hincrbyfloat($metricsKey, 'total_duration_ms', $durationMs);
            $pipe->hincrbyfloat($metricsKey, 'total_memory_mb', $memoryMb);
            $pipe->hincrbyfloat($metricsKey, 'total_cpu_time_ms', $cpuTimeMs);
            $pipe->hset($metricsKey, 'last_processed_at', $completedAt->timestamp);

            // Store duration sample (sorted set with timestamp as score)
            $pipe->zadd($durationKey, [$durationMs => $completedAt->timestamp]);
            $pipe->expire($durationKey, $this->redis->getTtl('raw'));

            // Store memory sample
            $pipe->zadd($memoryKey, [$memoryMb => $completedAt->timestamp]);
            $pipe->expire($memoryKey, $this->redis->getTtl('raw'));

            // Store CPU time sample
            $pipe->zadd($cpuKey, [$cpuTimeMs => $completedAt->timestamp]);
            $pipe->expire($cpuKey, $this->redis->getTtl('raw'));

            // Keep only recent samples (limit to 10000)
            $pipe->zremrangebyrank($durationKey, 0, -10001);
            $pipe->zremrangebyrank($memoryKey, 0, -10001);
            $pipe->zremrangebyrank($cpuKey, 0, -10001);
        });

        // Clean up job tracking key
        $redis->del($this->redis->key('job', $jobId));
    }

    public function recordFailure(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
    ): void {
        $redis = $this->redis->getConnection();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);

        $redis->pipeline(function ($pipe) use ($metricsKey, $exception, $failedAt) {
            $pipe->hincrby($metricsKey, 'total_failed', 1);
            $pipe->hset($metricsKey, 'last_failed_at', $failedAt->timestamp);
            $pipe->hset($metricsKey, 'last_exception', substr($exception, 0, 1000));
        });

        // Clean up job tracking key
        $redis->del($this->redis->key('job', $jobId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(
        string $jobClass,
        string $connection,
        string $queue,
    ): array {
        $key = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $redis = $this->redis->getConnection();

        /** @var array<string, string> */
        $data = $redis->hgetall($key) ?: [];

        return [
            'total_processed' => (int) ($data['total_processed'] ?? 0),
            'total_failed' => (int) ($data['total_failed'] ?? 0),
            'total_duration_ms' => (float) ($data['total_duration_ms'] ?? 0.0),
            'total_memory_mb' => (float) ($data['total_memory_mb'] ?? 0.0),
            'total_cpu_time_ms' => (float) ($data['total_cpu_time_ms'] ?? 0.0),
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
        $key = $this->redis->key('durations', $connection, $queue, $jobClass);
        $redis = $this->redis->getConnection();

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
        $key = $this->redis->key('memory', $connection, $queue, $jobClass);
        $redis = $this->redis->getConnection();

        /** @var array<string> */
        $samples = $redis->zrevrange($key, 0, $limit - 1);

        return array_map('floatval', $samples);
    }

    /**
     * @return array<int, float>
     */
    public function getCpuTimeSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->redis->key('cpu', $connection, $queue, $jobClass);
        $redis = $this->redis->getConnection();

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
        $key = $this->redis->key('durations', $connection, $queue, $jobClass);
        $redis = $this->redis->getConnection();

        $cutoff = Carbon::now()->subSeconds($windowSeconds)->timestamp;

        // Count samples within time window
        return (int) $redis->zcount($key, (string) $cutoff, '+inf');
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->redis->key('jobs', '*');
        $keys = $this->scanKeys($pattern);
        $redis = $this->redis->getConnection();
        $deleted = 0;

        foreach ($keys as $key) {
            $lastProcessed = $redis->hget($key, 'last_processed_at');

            if ($lastProcessed === null || $lastProcessed === false) {
                continue;
            }

            $age = Carbon::now()->timestamp - (int) $lastProcessed;

            if ($age > $olderThanSeconds) {
                $redis->del($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string>
     */
    private function scanKeys(string $pattern): array
    {
        $redis = $this->redis->getConnection();
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: string, 1: array<string>} */
            $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 100]);
            [$cursor, $found] = $result;
            $keys = array_merge($keys, $found);
        } while ($cursor !== '0');

        return $keys;
    }
}
