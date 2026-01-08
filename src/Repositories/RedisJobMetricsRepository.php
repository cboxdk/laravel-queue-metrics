<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
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
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->redis->key('job', (string) $jobId);
        $jobDiscoveryKey = $this->redis->key('discovery', 'jobs');
        $queueDiscoveryKey = $this->redis->key('discovery', 'queues');
        $ttl = $this->redis->getTtl('raw');
        $discoveryTtl = $this->redis->getTtl('aggregated');

        // Use transaction to ensure atomic registration of both discovery sets with metrics
        $this->redis->transaction(function ($pipe) use (
            $jobDiscoveryKey,
            $queueDiscoveryKey,
            $metricsKey,
            $jobKey,
            $connection,
            $queue,
            $jobClass,
            $startedAt,
            $ttl,
            $discoveryTtl
        ) {
            // Register queue in discovery set (push-based tracking)
            $pipe->addToSet($queueDiscoveryKey, ["{$connection}:{$queue}"]);
            $pipe->expire($queueDiscoveryKey, $discoveryTtl);

            // Register job in discovery set (push-based tracking)
            $pipe->addToSet($jobDiscoveryKey, ["{$connection}:{$queue}:{$jobClass}"]);
            $pipe->expire($jobDiscoveryKey, $discoveryTtl);

            // Increment total queued counter
            $pipe->incrementHashField($metricsKey, 'total_queued', 1);

            // Store job start time
            $pipe->setHash($jobKey, [
                'job_class' => $jobClass,
                'connection' => $connection,
                'queue' => $queue,
                'started_at' => $startedAt->timestamp,
            ], $ttl);

            // Ensure TTL is set on metrics key
            $pipe->expire($metricsKey, $ttl);
        });
    }

    public function recordCompletion(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        float $durationMs,
        float $memoryMb,
        float $cpuTimeMs,
        Carbon $completedAt,
        ?string $hostname = null,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $durationKey = $this->redis->key('durations', $connection, $queue, $jobClass);
        $memoryKey = $this->redis->key('memory', $connection, $queue, $jobClass);
        $cpuKey = $this->redis->key('cpu', $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');

        // Increment counters and store samples atomically using MULTI/EXEC transaction
        $this->redis->transaction(function ($pipe) use (
            $metricsKey,
            $durationKey,
            $memoryKey,
            $cpuKey,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $completedAt,
            $ttl,
            $jobId
        ) {
            $pipe->incrementHashField($metricsKey, 'total_processed', 1);
            $pipe->incrementHashField($metricsKey, 'total_duration_ms', $durationMs);
            $pipe->incrementHashField($metricsKey, 'total_memory_mb', $memoryMb);
            $pipe->incrementHashField($metricsKey, 'total_cpu_time_ms', $cpuTimeMs);
            $pipe->setHash($metricsKey, ['last_processed_at' => $completedAt->timestamp]);

            // Store samples in sorted sets with timestamp as score
            // Use a unique member format: "jobId:value" to ensure each job gets a separate entry
            // This allows multiple jobs with the same duration/memory/cpu to be stored
            $durationMember = $jobId.':'.$durationMs;
            /** @var array<string, int> $durationSample */
            $durationSample = [$durationMember => (int) $completedAt->timestamp];
            $pipe->addToSortedSet($durationKey, $durationSample, $ttl);

            // Store memory sample with unique member
            $memoryMember = $jobId.':'.$memoryMb;
            /** @var array<string, int> $memorySample */
            $memorySample = [$memoryMember => (int) $completedAt->timestamp];
            $pipe->addToSortedSet($memoryKey, $memorySample, $ttl);

            // Store CPU time sample with unique member
            $cpuMember = $jobId.':'.$cpuTimeMs;
            /** @var array<string, int> $cpuSample */
            $cpuSample = [$cpuMember => (int) $completedAt->timestamp];
            $pipe->addToSortedSet($cpuKey, $cpuSample, $ttl);

            // Refresh TTL on metrics key
            $pipe->expire($metricsKey, $ttl);

            // Keep only recent samples (limit to 10000)
            $pipe->removeSortedSetByRank($durationKey, 0, -10001);
            $pipe->removeSortedSetByRank($memoryKey, 0, -10001);
            $pipe->removeSortedSetByRank($cpuKey, 0, -10001);
        });

        // Store hostname-scoped metrics if hostname is provided
        if ($hostname !== null) {
            $this->recordHostnameMetrics(
                $hostname,
                $connection,
                $queue,
                $jobClass,
                $durationMs,
                true,
                $completedAt
            );
        }

        // Clean up job tracking key
        $this->redis->driver()->delete($this->redis->key('job', (string) $jobId));
    }

    public function recordFailure(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exception,
        Carbon $failedAt,
        ?string $hostname = null,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->redis->key('job', (string) $jobId);
        $ttl = $this->redis->getTtl('raw');

        if ($hostname !== null) {
            // Include hostname metrics in the same transaction
            $serverKey = $this->redis->key('server_jobs', $hostname, $connection, $queue, $jobClass);

            $this->redis->transaction(function ($pipe) use (
                $metricsKey,
                $serverKey,
                $jobKey,
                $exception,
                $failedAt,
                $ttl
            ) {
                // Job-level failure metrics
                $pipe->incrementHashField($metricsKey, 'total_failed', 1);
                $pipe->setHash($metricsKey, [
                    'last_failed_at' => $failedAt->timestamp,
                    'last_exception' => substr($exception, 0, 1000),
                ]);
                $pipe->expire($metricsKey, $ttl);

                // Hostname-level failure metrics
                $pipe->incrementHashField($serverKey, 'total_failed', 1);
                $pipe->setHash($serverKey, ['last_updated_at' => $failedAt->timestamp]);
                $pipe->expire($serverKey, $ttl);

                // Clean up job tracking key
                $pipe->delete($jobKey);
            });
        } else {
            // Transaction without hostname metrics
            $this->redis->transaction(function ($pipe) use ($metricsKey, $jobKey, $exception, $failedAt, $ttl) {
                $pipe->incrementHashField($metricsKey, 'total_failed', 1);
                $pipe->setHash($metricsKey, [
                    'last_failed_at' => $failedAt->timestamp,
                    'last_exception' => substr($exception, 0, 1000),
                ]);
                $pipe->expire($metricsKey, $ttl);

                // Clean up job tracking key
                $pipe->delete($jobKey);
            });
        }
    }

    /**
     * Record hostname-scoped job metrics for server-level aggregation.
     */
    private function recordHostnameMetrics(
        string $hostname,
        string $connection,
        string $queue,
        string $jobClass,
        float $durationMs,
        bool $success,
        Carbon $timestamp,
    ): void {
        $serverKey = $this->redis->key('server_jobs', $hostname, $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');

        // Use transaction instead of pipeline to ensure atomicity
        $this->redis->transaction(function ($pipe) use ($serverKey, $durationMs, $success, $timestamp, $ttl) {
            if ($success) {
                $pipe->incrementHashField($serverKey, 'total_processed', 1);
                $pipe->incrementHashField($serverKey, 'total_duration_ms', $durationMs);
            } else {
                $pipe->incrementHashField($serverKey, 'total_failed', 1);
            }
            $pipe->setHash($serverKey, ['last_updated_at' => $timestamp->timestamp]);
            $pipe->expire($serverKey, $ttl);
        });
    }

    /**
     * Get hostname-scoped job metrics for a specific server.
     *
     * @return array<string, array{total_processed: int, total_failed: int, total_duration_ms: float, failure_rate: float, avg_duration_ms: float}>
     */
    public function getHostnameJobMetrics(string $hostname): array
    {
        $pattern = $this->redis->key('server_jobs', $hostname, '*');
        $keys = $this->redis->driver()->scanKeys($pattern);
        $metrics = [];

        foreach ($keys as $key) {
            /** @var array<string, string> */
            $data = $this->redis->driver()->getHash($key) ?: [];

            $totalProcessed = (int) ($data['total_processed'] ?? 0);
            $totalFailed = (int) ($data['total_failed'] ?? 0);
            $totalDurationMs = (float) ($data['total_duration_ms'] ?? 0.0);

            $totalJobs = $totalProcessed + $totalFailed;
            $failureRate = $totalJobs > 0 ? ($totalFailed / $totalJobs) * 100 : 0.0;
            $avgDurationMs = $totalProcessed > 0 ? $totalDurationMs / $totalProcessed : 0.0;

            $metrics[$key] = [
                'total_processed' => $totalProcessed,
                'total_failed' => $totalFailed,
                'total_duration_ms' => $totalDurationMs,
                'failure_rate' => round($failureRate, 2),
                'avg_duration_ms' => round($avgDurationMs, 2),
            ];
        }

        return $metrics;
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
     * Get duration samples for job performance analysis.
     *
     * Returns the most recent duration measurements, with timestamps stored as
     * sorted set scores in Redis. Samples are stored in timestamp order, so
     * negative indices retrieve the most recent samples first.
     *
     * Sample Limit Behavior:
     * - If fewer than $limit samples exist, returns all available samples
     * - Samples are returned in chronological order (oldest to newest)
     * - Default limit of 1000 provides sufficient data for statistical analysis
     *
     * @return array<int, float> Duration values in milliseconds, chronologically ordered
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
        // Timestamps are used as sorted set scores for time-based querying
        /** @var array<string> */
        $samples = $driver->getSortedSetByRank($key, -$limit, -1);

        return array_map('floatval', array_reverse($samples));
    }

    /**
     * Get memory usage samples for resource analysis.
     *
     * Returns the most recent memory measurements, with timestamps stored as
     * sorted set scores in Redis. Samples are stored in timestamp order.
     *
     * Sample Limit Behavior:
     * - If fewer than $limit samples exist, returns all available samples
     * - Samples are returned in chronological order (oldest to newest)
     * - Timestamps in sorted set scores enable time-based filtering
     *
     * @return array<int, float> Memory values in megabytes, chronologically ordered
     */
    public function getMemorySamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->redis->key('memory', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        // Timestamps are used as sorted set scores for time-based querying
        /** @var array<string> */
        $samples = $driver->getSortedSetByRank($key, -$limit, -1);

        return array_map('floatval', array_reverse($samples));
    }

    /**
     * Get CPU time samples for performance analysis.
     *
     * Returns the most recent CPU time measurements, with timestamps stored as
     * sorted set scores in Redis. Samples are stored in timestamp order.
     *
     * Sample Limit Behavior:
     * - If fewer than $limit samples exist, returns all available samples
     * - Samples are returned in chronological order (oldest to newest)
     * - Timestamps in sorted set scores enable time-based filtering
     *
     * @return array<int, float> CPU time values in milliseconds, chronologically ordered
     */
    public function getCpuTimeSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->redis->key('cpu', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        // Timestamps are used as sorted set scores for time-based querying
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

        // Use Lua script to atomically calculate cutoff and count items
        // This prevents race conditions where items could be added between
        // calculating the cutoff timestamp and executing the count
        $script = <<<'LUA'
            local key = KEYS[1]
            local windowSeconds = tonumber(ARGV[1])
            local cutoff = redis.call('TIME')[1] - windowSeconds
            return redis.call('ZCOUNT', key, cutoff, '+inf')
        LUA;

        /** @var int */
        return $driver->eval($script, 1, $key, $windowSeconds);
    }

    public function getAverageDurationInWindow(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): float {
        $key = $this->redis->key('durations', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        // Use Lua script to atomically get samples within window and calculate average
        // This ensures consistency between throughput and average duration calculations
        $script = <<<'LUA'
            local key = KEYS[1]
            local windowSeconds = tonumber(ARGV[1])
            local cutoff = redis.call('TIME')[1] - windowSeconds

            -- Get all members in the window (members are "jobId:duration")
            local samples = redis.call('ZRANGEBYSCORE', key, cutoff, '+inf')

            if #samples == 0 then
                return 0
            end

            -- Parse members to extract duration values and calculate average
            -- Each member is formatted as "jobId:duration"
            local sum = 0
            local count = 0
            for i = 1, #samples do
                local member = samples[i]
                local colonPos = string.find(member, ":")
                if colonPos then
                    local duration = string.sub(member, colonPos + 1)
                    sum = sum + tonumber(duration)
                    count = count + 1
                end
            end

            if count == 0 then
                return 0
            end

            return sum / count
        LUA;

        /** @var float */
        $result = $driver->eval($script, 1, $key, $windowSeconds);

        return (float) $result;
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
        $driver->addToSortedSet($key, [(string) $queuedAt->timestamp => (int) $queuedAt->timestamp], $this->redis->getTtl('raw'));
    }

    public function recordRetryRequested(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $retryRequestedAt,
        int $attemptNumber,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $retryKey = $this->redis->key('retries', $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');
        $retryData = json_encode(['job_id' => $jobId, 'attempt' => $attemptNumber], JSON_THROW_ON_ERROR);

        // Use transaction to ensure atomic retry recording
        $this->redis->transaction(function ($pipe) use (
            $metricsKey,
            $retryKey,
            $retryData,
            $retryRequestedAt,
            $ttl
        ) {
            // Increment retry counter
            $pipe->incrementHashField($metricsKey, 'total_retries', 1);

            // Store retry event for pattern analysis
            $pipe->addToSortedSet($retryKey, [
                $retryData => (int) $retryRequestedAt->timestamp,
            ], $ttl);

            // Refresh TTL on metrics key
            $pipe->expire($metricsKey, $ttl);
        });
    }

    public function recordTimeout(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $timedOutAt,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');

        // Use transaction to ensure atomic timeout recording
        $this->redis->transaction(function ($pipe) use ($metricsKey, $timedOutAt, $ttl) {
            // Increment timeout counter
            $pipe->incrementHashField($metricsKey, 'total_timeouts', 1);
            $pipe->setHash($metricsKey, ['last_timeout_at' => $timedOutAt->timestamp]);

            // Refresh TTL on metrics key
            $pipe->expire($metricsKey, $ttl);
        });
    }

    public function recordException(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        string $exceptionClass,
        string $exceptionMessage,
        Carbon $occurredAt,
    ): void {
        $metricsKey = $this->redis->key('jobs', $connection, $queue, $jobClass);
        $exceptionsKey = $this->redis->key('exceptions', $connection, $queue, $jobClass);
        $ttl = $this->redis->getTtl('raw');
        $aggregatedTtl = $this->redis->getTtl('aggregated');

        // Use transaction to ensure atomic exception recording
        $this->redis->transaction(function ($pipe) use (
            $metricsKey,
            $exceptionsKey,
            $exceptionClass,
            $ttl,
            $aggregatedTtl
        ) {
            // Increment exception counter
            $pipe->incrementHashField($metricsKey, 'total_exceptions', 1);

            // Track exception types
            $pipe->incrementHashField($exceptionsKey, $exceptionClass, 1);
            $pipe->expire($exceptionsKey, $aggregatedTtl);

            // Refresh TTL on metrics key
            $pipe->expire($metricsKey, $ttl);
        });
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
            $age = (int) Carbon::now()->timestamp - $lastProcessedInt;

            if ($age > $olderThanSeconds) {
                $driver->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * List all discovered jobs using discovery set (O(N) instead of O(total_keys)).
     *
     * @return array<int, array{connection: string, queue: string, jobClass: string}>
     */
    public function listJobs(): array
    {
        $key = $this->redis->key('discovery', 'jobs');
        $members = $this->redis->driver()->getSetMembers($key);

        $jobs = [];
        foreach ($members as $member) {
            // Parse "connection:queue:JobClass" format
            $parts = explode(':', $member, 3);
            if (count($parts) === 3) {
                $jobs[] = [
                    'connection' => $parts[0],
                    'queue' => $parts[1],
                    'jobClass' => $parts[2],
                ];
            }
        }

        return $jobs;
    }

    /**
     * Register job in discovery set (push-based tracking).
     */
    public function markJobDiscovered(string $connection, string $queue, string $jobClass): void
    {
        $key = $this->redis->key('discovery', 'jobs');
        $this->redis->driver()->addToSet($key, ["{$connection}:{$queue}:{$jobClass}"]);
    }

    /**
     * @return array<string>
     */
    private function scanKeys(string $pattern): array
    {
        return $this->redis->driver()->scanKeys($pattern);
    }
}
