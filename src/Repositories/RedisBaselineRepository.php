<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

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
        $key = $this->redis->key('baseline', $baseline->connection, $baseline->queue);
        $driver = $this->redis->driver();

        $driver->setHash($key, [
            'connection' => $baseline->connection,
            'queue' => $baseline->queue,
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
        $key = $this->redis->key('baseline', $connection, $queue);
        $driver = $this->redis->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

        if (empty($data)) {
            return null;
        }

        return BaselineData::fromArray([
            'connection' => $data['connection'] ?? $connection,
            'queue' => $data['queue'] ?? $queue,
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
        $key = $this->redis->key('baseline', $connection, $queue);
        $this->redis->driver()->delete($key);
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
            $age = Carbon::now()->timestamp - $calculatedAtInt;

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
