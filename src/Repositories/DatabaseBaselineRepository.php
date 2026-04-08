<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

/**
 * Database-based implementation of baseline repository.
 */
final readonly class DatabaseBaselineRepository implements BaselineRepository
{
    public function __construct(
        private DatabaseMetricsStore $store,
    ) {}

    public function storeBaseline(BaselineData $baseline): void
    {
        $keyParts = $baseline->jobClass !== ''
            ? ['baseline', $baseline->connection, $baseline->queue, $baseline->jobClass]
            : ['baseline', $baseline->connection, $baseline->queue, '_aggregate'];

        $key = $this->store->key(...$keyParts);
        $driver = $this->store->driver();

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
        ], $this->store->getTtl('baseline'));
    }

    public function getBaseline(string $connection, string $queue): ?BaselineData
    {
        $key = $this->store->key('baseline', $connection, $queue, '_aggregate');
        $driver = $this->store->driver();

        /** @var array<string, string> $data */
        $data = $driver->getHash($key);

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
     * @param  array<int, array{connection: string, queue: string}>  $queuePairs
     * @return array<string, BaselineData>
     */
    public function getBaselines(array $queuePairs): array
    {
        if (empty($queuePairs)) {
            return [];
        }

        $baselines = [];

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
        $key = $this->store->key('baseline', $connection, $queue, $jobClass);
        $driver = $this->store->driver();

        /** @var array<string, string> $data */
        $data = $driver->getHash($key);

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
        $pattern = $this->store->key('baseline', $connection, $queue, '*');
        $keys = $this->scanHashKeys($pattern);
        $driver = $this->store->driver();

        $baselines = [];

        foreach ($keys as $key) {
            // Skip aggregated baseline
            if (str_ends_with($key, ':_aggregate')) {
                continue;
            }

            /** @var array<string, string> $data */
            $data = $driver->getHash($key);

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

        $age = (int) Carbon::now()->timestamp - (int) $baseline->calculatedAt->timestamp;

        return $age <= $maxAgeSeconds;
    }

    public function deleteBaseline(string $connection, string $queue): void
    {
        $pattern = $this->store->key('baseline', $connection, $queue, '*');
        $keys = $this->scanHashKeys($pattern);

        foreach ($keys as $key) {
            $this->store->driver()->delete($key);
        }
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->store->key('baseline', '*');
        $keys = $this->scanHashKeys($pattern);
        $driver = $this->store->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $calculatedAt = $driver->getHashField($key, 'calculated_at');

            if ($calculatedAt === null) {
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
     * Scan hash keys matching a pattern.
     *
     * Queries MetricsHash directly with LIKE pattern, consistent with
     * the approach used in DatabaseJobMetricsRepository and DatabaseQueueMetricsRepository.
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
