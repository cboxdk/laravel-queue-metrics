<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

/**
 * Database-based implementation of job metrics repository.
 */
final readonly class DatabaseJobMetricsRepository implements JobMetricsRepository
{
    public function __construct(
        private DatabaseMetricsStore $store,
    ) {}

    public function recordStart(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $startedAt,
    ): void {
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->store->key('job', (string) $jobId);
        $jobDiscoveryKey = $this->store->key('discovery', 'jobs');
        $queueDiscoveryKey = $this->store->key('discovery', 'queues');
        $ttl = $this->store->getTtl('raw');
        $discoveryTtl = $this->store->getTtl('aggregated');

        $driver = $this->store->driver();

        $this->store->transaction(function () use (
            $driver,
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
            $driver->addToSet($queueDiscoveryKey, ["{$connection}:{$queue}"]);
            $driver->expire($queueDiscoveryKey, $discoveryTtl);

            // Register job in discovery set (push-based tracking)
            $driver->addToSet($jobDiscoveryKey, ["{$connection}:{$queue}:{$jobClass}"]);
            $driver->expire($jobDiscoveryKey, $discoveryTtl);

            // Increment total queued counter
            $driver->incrementHashField($metricsKey, 'total_queued', 1);

            // Store job start time
            $driver->setHash($jobKey, [
                'job_class' => $jobClass,
                'connection' => $connection,
                'queue' => $queue,
                'started_at' => $startedAt->timestamp,
            ], $ttl);

            // Ensure TTL is set on metrics key
            $driver->expire($metricsKey, $ttl);
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
        float $memoryIncrementalMb = 0.0,
    ): void {
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $durationKey = $this->store->key('durations', $connection, $queue, $jobClass);
        $memoryKey = $this->store->key('memory', $connection, $queue, $jobClass);
        $cpuKey = $this->store->key('cpu', $connection, $queue, $jobClass);
        $ttl = $this->store->getTtl('raw');

        $driver = $this->store->driver();

        $this->store->transaction(function () use (
            $driver,
            $metricsKey,
            $durationKey,
            $memoryKey,
            $cpuKey,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $memoryIncrementalMb,
            $completedAt,
            $ttl,
            $jobId
        ) {
            $driver->incrementHashFields($metricsKey, [
                'total_processed' => 1,
                'total_duration_ms' => $durationMs,
                'total_memory_mb' => $memoryMb,
                'total_cpu_time_ms' => $cpuTimeMs,
                'total_memory_incremental_mb' => $memoryIncrementalMb,
            ]);
            $driver->setHash($metricsKey, ['last_processed_at' => $completedAt->timestamp]);

            // Store samples in sorted sets with timestamp as score
            // Member format: "jobId:value" for unique entries per job
            $durationMember = $jobId.':'.$durationMs;
            $driver->addToSortedSet($durationKey, [$durationMember => (int) $completedAt->timestamp], $ttl);

            $memoryMember = $jobId.':'.$memoryMb;
            $driver->addToSortedSet($memoryKey, [$memoryMember => (int) $completedAt->timestamp], $ttl);

            $cpuMember = $jobId.':'.$cpuTimeMs;
            $driver->addToSortedSet($cpuKey, [$cpuMember => (int) $completedAt->timestamp], $ttl);

            // Refresh TTL on metrics key
            $driver->expire($metricsKey, $ttl);

            // Keep only recent samples (limit to 10000)
            if (mt_rand(1, 100) <= 2) {
                $driver->removeSortedSetByRank($durationKey, 0, -10001);
                $driver->removeSortedSetByRank($memoryKey, 0, -10001);
                $driver->removeSortedSetByRank($cpuKey, 0, -10001);
            }
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
        $this->store->driver()->delete($this->store->key('job', (string) $jobId));
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
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->store->key('job', (string) $jobId);
        $ttl = $this->store->getTtl('raw');

        $driver = $this->store->driver();

        if ($hostname !== null) {
            $serverKey = $this->store->key('server_jobs', $hostname, $connection, $queue, $jobClass);
            $discoveryKey = $this->store->key('discovery', 'server_jobs', $hostname);

            $this->store->transaction(function () use (
                $driver,
                $metricsKey,
                $serverKey,
                $discoveryKey,
                $jobKey,
                $exception,
                $failedAt,
                $ttl
            ) {
                // Job-level failure metrics (combine increment + setHash into one transaction)
                $driver->incrementHashFields($metricsKey, ['total_failed' => 1]);
                $driver->setHash($metricsKey, [
                    'last_failed_at' => $failedAt->timestamp,
                    'last_exception' => substr($exception, 0, 1000),
                ]);
                $driver->expire($metricsKey, $ttl);

                // Register server key in discovery set
                $driver->addToSet($discoveryKey, [$serverKey]);
                $driver->expire($discoveryKey, $ttl);

                // Hostname-level failure metrics (combine increment + setHash into one transaction)
                $driver->incrementHashFields($serverKey, ['total_failed' => 1]);
                $driver->setHash($serverKey, ['last_updated_at' => $failedAt->timestamp]);
                $driver->expire($serverKey, $ttl);

                // Clean up job tracking key
                $driver->delete($jobKey);
            });
        } else {
            $this->store->transaction(function () use ($driver, $metricsKey, $jobKey, $exception, $failedAt, $ttl) {
                $driver->incrementHashFields($metricsKey, ['total_failed' => 1]);
                $driver->setHash($metricsKey, [
                    'last_failed_at' => $failedAt->timestamp,
                    'last_exception' => substr($exception, 0, 1000),
                ]);
                $driver->expire($metricsKey, $ttl);

                // Clean up job tracking key
                $driver->delete($jobKey);
            });
        }
    }

    public function recordDebounced(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $debouncedAt,
    ): void {
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $jobKey = $this->store->key('job', (string) $jobId);
        $ttl = $this->store->getTtl('raw');

        $driver = $this->store->driver();

        $this->store->transaction(function () use ($driver, $metricsKey, $jobKey, $debouncedAt, $ttl) {
            $driver->incrementHashField($metricsKey, 'total_debounced', 1);
            $driver->setHash($metricsKey, ['last_debounced_at' => $debouncedAt->timestamp]);
            $driver->expire($metricsKey, $ttl);

            // Clean up job tracking key (started in recordStart)
            $driver->delete($jobKey);
        });
    }

    /**
     * @return array<string, array{total_processed: int, total_failed: int, total_duration_ms: float, failure_rate: float, avg_duration_ms: float}>
     */
    public function getHostnameJobMetrics(string $hostname): array
    {
        $driver = $this->store->driver();

        // Use discovery set to find server job keys
        $discoveryKey = $this->store->key('discovery', 'server_jobs', $hostname);
        $keys = $driver->getSetMembers($discoveryKey);

        // Fallback: scan hash keys matching pattern
        if (empty($keys)) {
            $keys = $this->scanHashKeys($this->store->key('server_jobs', $hostname, '*'));
        }

        $metrics = [];

        foreach ($keys as $key) {
            /** @var array<string, string> $data */
            $data = $driver->getHash($key);

            if (empty($data)) {
                continue;
            }

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
        $key = $this->store->key('jobs', $connection, $queue, $jobClass);
        $driver = $this->store->driver();

        /** @var array<string, string> $data */
        $data = $driver->getHash($key);

        return [
            'total_processed' => (int) ($data['total_processed'] ?? 0),
            'total_failed' => (int) ($data['total_failed'] ?? 0),
            'total_debounced' => (int) ($data['total_debounced'] ?? 0),
            'last_debounced_at' => isset($data['last_debounced_at'])
                ? Carbon::createFromTimestamp((int) $data['last_debounced_at'])
                : null,
            'total_duration_ms' => (float) ($data['total_duration_ms'] ?? 0.0),
            'total_memory_mb' => (float) ($data['total_memory_mb'] ?? 0.0),
            'total_cpu_time_ms' => (float) ($data['total_cpu_time_ms'] ?? 0.0),
            'total_memory_incremental_mb' => (float) ($data['total_memory_incremental_mb'] ?? 0.0),
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
     * Returns the most recent duration measurements in chronological order.
     * Samples are stored as "jobId:value" members in sorted sets with
     * timestamp scores for time-based querying.
     *
     * @return array<int, float> Duration values in milliseconds, chronologically ordered
     */
    public function getDurationSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->store->key('durations', $connection, $queue, $jobClass);

        return $this->parseSamples(
            $this->store->driver()->getSortedSetByRank($key, -$limit, -1)
        );
    }

    /**
     * Get memory usage samples for resource analysis.
     *
     * @return array<int, float> Memory values in megabytes, chronologically ordered
     */
    public function getMemorySamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->store->key('memory', $connection, $queue, $jobClass);

        return $this->parseSamples(
            $this->store->driver()->getSortedSetByRank($key, -$limit, -1)
        );
    }

    /**
     * Get CPU time samples for performance analysis.
     *
     * @return array<int, float> CPU time values in milliseconds, chronologically ordered
     */
    public function getCpuTimeSamples(
        string $jobClass,
        string $connection,
        string $queue,
        int $limit = 1000,
    ): array {
        $key = $this->store->key('cpu', $connection, $queue, $jobClass);

        return $this->parseSamples(
            $this->store->driver()->getSortedSetByRank($key, -$limit, -1)
        );
    }

    public function getThroughput(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): int {
        $key = $this->store->key('durations', $connection, $queue, $jobClass);
        $cutoff = (string) Carbon::now()->subSeconds($windowSeconds)->timestamp;

        return $this->store->driver()->countSortedSetByScore($key, $cutoff, '+inf');
    }

    public function getAverageDurationInWindow(
        string $jobClass,
        string $connection,
        string $queue,
        int $windowSeconds,
    ): float {
        $key = $this->store->key('durations', $connection, $queue, $jobClass);
        $cutoff = (string) Carbon::now()->subSeconds($windowSeconds)->timestamp;
        $samples = $this->store->driver()->getSortedSetByScore($key, $cutoff, '+inf');

        if (empty($samples)) {
            return 0.0;
        }

        $sum = 0.0;
        $count = 0;
        foreach ($samples as $member) {
            $colonPos = strrpos($member, ':');
            if ($colonPos !== false) {
                $sum += (float) substr($member, $colonPos + 1);
                $count++;
            }
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    public function recordQueuedAt(
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $queuedAt,
    ): void {
        $key = $this->store->key('queued', $connection, $queue, $jobClass);
        $driver = $this->store->driver();

        $driver->addToSortedSet(
            $key,
            [(string) $queuedAt->timestamp => (int) $queuedAt->timestamp],
            $this->store->getTtl('raw')
        );
    }

    public function recordRetryRequested(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $retryRequestedAt,
        int $attemptNumber,
    ): void {
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $retryKey = $this->store->key('retries', $connection, $queue, $jobClass);
        $ttl = $this->store->getTtl('raw');
        $retryData = json_encode(['job_id' => $jobId, 'attempt' => $attemptNumber], JSON_THROW_ON_ERROR);

        $driver = $this->store->driver();

        $this->store->transaction(function () use (
            $driver,
            $metricsKey,
            $retryKey,
            $retryData,
            $retryRequestedAt,
            $ttl
        ) {
            // Increment retry counter
            $driver->incrementHashField($metricsKey, 'total_retries', 1);

            // Store retry event for pattern analysis
            $driver->addToSortedSet($retryKey, [
                $retryData => (int) $retryRequestedAt->timestamp,
            ], $ttl);

            // Refresh TTL on metrics key
            $driver->expire($metricsKey, $ttl);
        });
    }

    public function recordTimeout(
        string|int $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Carbon $timedOutAt,
    ): void {
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $ttl = $this->store->getTtl('raw');

        $driver = $this->store->driver();

        $this->store->transaction(function () use ($driver, $metricsKey, $timedOutAt, $ttl) {
            $driver->incrementHashField($metricsKey, 'total_timeouts', 1);
            $driver->setHash($metricsKey, ['last_timeout_at' => $timedOutAt->timestamp]);
            $driver->expire($metricsKey, $ttl);
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
        $metricsKey = $this->store->key('jobs', $connection, $queue, $jobClass);
        $exceptionsKey = $this->store->key('exceptions', $connection, $queue, $jobClass);
        $ttl = $this->store->getTtl('raw');
        $aggregatedTtl = $this->store->getTtl('aggregated');

        $driver = $this->store->driver();

        $this->store->transaction(function () use (
            $driver,
            $metricsKey,
            $exceptionsKey,
            $exceptionClass,
            $ttl,
            $aggregatedTtl
        ) {
            // Increment exception counter
            $driver->incrementHashField($metricsKey, 'total_exceptions', 1);

            // Track exception types
            $driver->incrementHashField($exceptionsKey, $exceptionClass, 1);
            $driver->expire($exceptionsKey, $aggregatedTtl);

            // Refresh TTL on metrics key
            $driver->expire($metricsKey, $ttl);
        });
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->store->key('jobs', '*');
        $keys = $this->scanHashKeys($pattern);
        $driver = $this->store->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $data = $driver->getHash($key);
            $lastProcessed = $data['last_processed_at'] ?? null;

            if ($lastProcessed === null) {
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
     * List all discovered jobs using discovery set.
     *
     * @return array<int, array{connection: string, queue: string, jobClass: string}>
     */
    public function listJobs(): array
    {
        $key = $this->store->key('discovery', 'jobs');
        $members = $this->store->driver()->getSetMembers($key);

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
        $key = $this->store->key('discovery', 'jobs');
        $ttl = $this->store->getTtl('aggregated');

        $this->store->driver()->addToSet($key, ["{$connection}:{$queue}:{$jobClass}"], $ttl);
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
        $serverKey = $this->store->key('server_jobs', $hostname, $connection, $queue, $jobClass);
        $discoveryKey = $this->store->key('discovery', 'server_jobs', $hostname);
        $ttl = $this->store->getTtl('raw');

        $driver = $this->store->driver();

        $this->store->transaction(function () use ($driver, $serverKey, $discoveryKey, $durationMs, $success, $timestamp, $ttl) {
            $driver->addToSet($discoveryKey, [$serverKey]);
            $driver->expire($discoveryKey, $ttl);

            if ($success) {
                $driver->incrementHashFields($serverKey, [
                    'total_processed' => 1,
                    'total_duration_ms' => $durationMs,
                ]);
            } else {
                $driver->incrementHashFields($serverKey, ['total_failed' => 1]);
            }
            $driver->setHash($serverKey, ['last_updated_at' => $timestamp->timestamp]);
            $driver->expire($serverKey, $ttl);
        });
    }

    /**
     * Parse sample members from sorted set format "jobId:value" to float values.
     *
     * Members are stored as "{jobId}:{value}" (e.g., "job-1:150.0") and returned
     * in reverse chronological order from getSortedSetByRank. This method extracts
     * the numeric value after the last colon and returns samples in chronological order.
     *
     * @param  array<int, string>  $samples  Raw members from sorted set
     * @return array<int, float> Parsed float values in chronological order
     */
    private function parseSamples(array $samples): array
    {
        if (empty($samples)) {
            return [];
        }

        return collect($samples)
            ->map(function (string $member): float {
                $colonPos = strrpos($member, ':');

                return $colonPos !== false ? (float) substr($member, $colonPos + 1) : 0.0;
            })
            ->values()
            ->all();
    }

    /**
     * Scan hash keys matching a pattern.
     *
     * Unlike scanKeys on the store (which scans MetricsKey), this method
     * scans MetricsHash for keys matching the given LIKE pattern.
     *
     * @return array<int, string>
     */
    private function scanHashKeys(string $pattern): array
    {
        $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $pattern);
        $sqlPattern = str_replace(['*', '?'], ['%', '_'], $escaped);

        /** @var array<int, string> */
        return MetricsHash::notExpired()
            ->where('key', 'like', $sqlPattern)
            ->pluck('key')
            ->all();
    }
}
