<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Illuminate\Redis\Connections\Connection;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Redis-based implementation of job metrics repository.
 */
final readonly class RedisJobMetricsRepository implements JobMetricsRepository
{
    public function __construct(
        private RedisMetricsStore $redis,
    ) {}

    public function recordStart(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void {
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->redis->key('job', $jobId);

        // Increment total queued counter
        $driver->incrementHashField($metricsKey, 'total_queued', 1);

        // Store job start time
        $driver->setHash($jobKey, [
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => $startedAt->timestamp,
        ], $this->redis->getTtl('raw'));
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
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $durationKey = $this->redis->key('durations', $connection, $queue, $jobClass);
        $memoryKey = $this->redis->key('memory', $connection, $queue, $jobClass);
        $cpuKey = $this->redis->key('cpu', $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');

        // Increment counters and store samples atomically
        $driver->pipeline(function ($pipe) use (
            $metricsKey,
            $durationKey,
            $memoryKey,
            $cpuKey,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $completedAt,
            $ttl
        ) {
            $pipe->incrementHashField($metricsKey, 'total_processed', 1);
            $pipe->incrementHashField($metricsKey, 'total_duration_ms', $durationMs);
            $pipe->incrementHashField($metricsKey, 'total_memory_mb', $memoryMb);
            $pipe->incrementHashField($metricsKey, 'total_cpu_time_ms', $cpuTimeMs);
            $pipe->setHash($metricsKey, ['last_processed_at' => $completedAt->timestamp]);

            // Store duration sample (sorted set with timestamp as score)
            $pipe->addToSortedSet($durationKey, [(string) $durationMs => $completedAt->timestamp], $ttl);

            // Store memory sample
            $pipe->addToSortedSet($memoryKey, [(string) $memoryMb => $completedAt->timestamp], $ttl);

            // Store CPU time sample
            $pipe->addToSortedSet($cpuKey, [(string) $cpuTimeMs => $completedAt->timestamp], $ttl);

            // Keep only recent samples (limit to 10000)
            $pipe->removeSortedSetByRank($durationKey, 0, -10001);
            $pipe->removeSortedSetByRank($memoryKey, 0, -10001);
            $pipe->removeSortedSetByRank($cpuKey, 0, -10001);
        });

        // Clean up job tracking key
        $driver->delete($this->redis->key('job', $jobId));
    }

    public function recordFailure(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
    ): void {
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);

        $driver->pipeline(function ($pipe) use ($metricsKey, $exception, $failedAt) {
            $pipe->incrementHashField($metricsKey, 'total_failed', 1);
            $pipe->setHash($metricsKey, [
                'last_failed_at' => $failedAt->timestamp,
                'last_exception' => substr($exception, 0, 1000),
            ]);
        });

        // Clean up job tracking key
        $driver->delete($this->redis->key('job', $jobId));
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
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

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
        $driver = $this->redis->driver();

        // Get most recent samples (reverse order, so use negative indices)
        /** @var array<string> */
        $samples = $driver->getSortedSetByRank($key, -$limit, -1);

        return array_map('floatval', array_reverse($samples));
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
        $driver = $this->redis->driver();

        /** @var array<string> */
        $samples = $driver->getSortedSetByRank($key, -$limit, -1);

        return array_map('floatval', array_reverse($samples));
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
        $driver = $this->redis->driver();

        /** @var array<string> */
        $samples = $driver->getSortedSetByRank($key, -$limit, -1);

        return array_map('floatval', array_reverse($samples));
    }

    public function getThroughput(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): int {
        $key = $this->redis->key('durations', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        $cutoff = Carbon::now()->subSeconds($windowSeconds)->timestamp;

        // Count samples within time window
        return $driver->countSortedSetByScore($key, (string) $cutoff, '+inf');
    }

    public function recordQueuedAt(
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $queuedAt,
    ): void {
        // Implementation for tracking when jobs are queued
        $key = $this->redis->key('queued', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        // Store timestamp as sorted set for time-to-start calculations
        $driver->addToSortedSet($key, [(string) $queuedAt->timestamp => $queuedAt->timestamp], $this->redis->getTtl('raw'));
    }

    public function recordRetryRequested(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $retryRequestedAt,
        int $attemptNumber,
    ): void {
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $retryKey = $this->redis->key('retries', $connection, $queue, $jobClass);

        // Increment retry counter
        $driver->incrementHashField($metricsKey, 'total_retries', 1);

        // Store retry event for pattern analysis
        $driver->addToSortedSet($retryKey, [
            json_encode(['job_id' => $jobId, 'attempt' => $attemptNumber], JSON_THROW_ON_ERROR) => $retryRequestedAt->timestamp,
        ], $this->redis->getTtl('raw'));
    }

    public function recordTimeout(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $timedOutAt,
    ): void {
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);

        // Increment timeout counter
        $driver->incrementHashField($metricsKey, 'total_timeouts', 1);
        $driver->setHash($metricsKey, ['last_timeout_at' => $timedOutAt->timestamp]);
    }

    public function recordException(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exceptionClass,
        string $exceptionMessage,
        Carbon $occurredAt,
    ): void {
        $driver = $this->redis->driver();
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $exceptionsKey = $this->redis->key('exceptions', $connection, $queue, $jobClass);

        // Increment exception counter
        $driver->incrementHashField($metricsKey, 'total_exceptions', 1);

        // Track exception types
        $driver->incrementHashField($exceptionsKey, $exceptionClass, 1);
        $driver->expire($exceptionsKey, $this->redis->getTtl('aggregated'));
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->redis->key('jobs', '*');
        $keys = $this->scanKeys($pattern);
        $driver = $this->redis->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $lastProcessed = $driver->getHashField($key, 'last_processed_at');

            if ($lastProcessed === null || $lastProcessed === false) {
                continue;
            }

            $lastProcessedInt = is_numeric($lastProcessed) ? (int) $lastProcessed : 0;
            $age = Carbon::now()->timestamp - $lastProcessedInt;

            if ($age > $olderThanSeconds) {
                $driver->delete($key);
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
        return $this->redis->driver()->scanKeys($pattern);
    }
}
