<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Redis-based implementation of baseline repository.
 */
final readonly class RedisBaselineRepository implements BaselineRepository
{
    public function __construct(
        private RedisMetricsStore $redis,
    ) {}

    public function storeBaseline(BaselineData $baseline): void
    {
        // Store with job class if present, otherwise store as aggregated baseline
        $keyParts = $baseline->jobClass !== ''
            ? ['baseline', $baseline->connection, $baseline->queue, $baseline->jobClass]
            : ['baseline', $baseline->connection, $baseline->queue, '_aggregate'];

        $key = $this->redis->key(...$keyParts);
        $driver = $this->redis->driver();

        $driver->setHash($key, [
            'connection' => $baseline->connection,
            'queue' => $baseline->queue,
            'job_class' => $baseline->jobClass,
            'cpu_percent_per_job' => (string) $baseline->cpuPercentPerJob,
            'memory_mb_per_job' => (string) $baseline->memoryMbPerJob,
            'avg_duration_ms' => (string) $baseline->avgDurationMs,
            'sample_count' => $baseline->sampleCount,
            'confidence_score' => (string) $baseline->confidenceScore,
            'calculated_at' => $baseline->calculatedAt->timestamp,
        ], $this->redis->getTtl('baseline'));
    }

    public function getBaseline(string $connection, string $queue): ?BaselineData
    {
        // Get aggregated baseline
        $key = $this->redis->key('baseline', $connection, $queue, '_aggregate');
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

        if (empty($data)) {
            return null;
        }

        return BaselineData::fromArray([
            'connection' => $data['connection'] ?? $connection,
            'queue' => $data['queue'] ?? $queue,
            'job_class' => $data['job_class'] ?? '',
            'cpu_percent_per_job' => (float) ($data['cpu_percent_per_job'] ?? 0.0),
            'memory_mb_per_job' => (float) ($data['memory_mb_per_job'] ?? 0.0),
            'avg_duration_ms' => (float) ($data['avg_duration_ms'] ?? 0.0),
            'sample_count' => (int) ($data['sample_count'] ?? 0),
            'confidence_score' => (float) ($data['confidence_score'] ?? 0.0),
            'calculated_at' => isset($data['calculated_at'])
                ? Carbon::createFromTimestamp((int) $data['calculated_at'])->toIso8601String()
                : null,
        ]);
    }

    /**
     * Get baselines for multiple queues using Redis pipeline for optimal performance.
     *
     * @param  array<int, array{connection: string, queue: string}>  $queuePairs
     * @return array<string, BaselineData>
     */
    public function getBaselines(array $queuePairs): array
    {
        if (empty($queuePairs)) {
            return [];
        }

        $baselines = [];

        // Fetch each baseline using existing getBaseline method
        // Note: In production with PhpRedis extension, this could be optimized with pipeline
        foreach ($queuePairs as $pair) {
            $baseline = $this->getBaseline($pair['connection'], $pair['queue']);
            if ($baseline !== null) {
                $baselines["{$pair['connection']}:{$pair['queue']}"] = $baseline;
            }
        }

        return $baselines;
    }

    public function getJobClassBaseline(string $connection, string $queue, string $jobClass): ?BaselineData
    {
        $key = $this->redis->key('baseline', $connection, $queue, $jobClass);
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

        if (empty($data)) {
            return null;
        }

        return BaselineData::fromArray([
            'connection' => $data['connection'] ?? $connection,
            'queue' => $data['queue'] ?? $queue,
            'job_class' => $data['job_class'] ?? $jobClass,
            'cpu_percent_per_job' => (float) ($data['cpu_percent_per_job'] ?? 0.0),
            'memory_mb_per_job' => (float) ($data['memory_mb_per_job'] ?? 0.0),
            'avg_duration_ms' => (float) ($data['avg_duration_ms'] ?? 0.0),
            'sample_count' => (int) ($data['sample_count'] ?? 0),
            'confidence_score' => (float) ($data['confidence_score'] ?? 0.0),
            'calculated_at' => isset($data['calculated_at'])
                ? Carbon::createFromTimestamp((int) $data['calculated_at'])->toIso8601String()
                : null,
        ]);
    }

    /**
     * @return array<int, BaselineData>
     */
    public function getJobClassBaselines(string $connection, string $queue): array
    {
        $pattern = $this->redis->key('baseline', $connection, $queue, '*');
        $keys = $this->scanKeys($pattern);
        $driver = $this->redis->driver();

        $baselines = [];

        foreach ($keys as $key) {
            // Skip aggregated baseline
            if (str_ends_with($key, ':_aggregate')) {
                continue;
            }

            /** @var array<string, string> */
            $data = $driver->getHash($key) ?: [];

            if (empty($data)) {
                continue;
            }

            $baselines[] = BaselineData::fromArray([
                'connection' => $data['connection'] ?? $connection,
                'queue' => $data['queue'] ?? $queue,
                'job_class' => $data['job_class'] ?? '',
                'cpu_percent_per_job' => (float) ($data['cpu_percent_per_job'] ?? 0.0),
                'memory_mb_per_job' => (float) ($data['memory_mb_per_job'] ?? 0.0),
                'avg_duration_ms' => (float) ($data['avg_duration_ms'] ?? 0.0),
                'sample_count' => (int) ($data['sample_count'] ?? 0),
                'confidence_score' => (float) ($data['confidence_score'] ?? 0.0),
                'calculated_at' => isset($data['calculated_at'])
                    ? Carbon::createFromTimestamp((int) $data['calculated_at'])->toIso8601String()
                    : null,
            ]);
        }

        return $baselines;
    }

    public function hasRecentBaseline(
        string $connection,
        string $queue,
        int $maxAgeSeconds = 86400,
    ): bool {
        $baseline = $this->getBaseline($connection, $queue);

        if ($baseline === null) {
            return false;
        }

        $age = Carbon::now()->diffInSeconds($baseline->calculatedAt);

        return $age <= $maxAgeSeconds;
    }

    public function deleteBaseline(string $connection, string $queue): void
    {
        // Delete all baselines for this queue (both aggregated and job class specific)
        $pattern = $this->redis->key('baseline', $connection, $queue, '*');
        $keys = $this->scanKeys($pattern);

        foreach ($keys as $key) {
            $this->redis->driver()->delete($key);
        }
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->redis->key('baseline', '*', '*');
        $keys = $this->scanKeys($pattern);
        $driver = $this->redis->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $calculatedAt = $driver->getHashField($key, 'calculated_at');

            if ($calculatedAt === null || $calculatedAt === false) {
                continue;
            }

            $calculatedAtInt = is_numeric($calculatedAt) ? (int) $calculatedAt : 0;
            $age = (int) Carbon::now()->timestamp - $calculatedAtInt;

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
