<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Contracts\QueueInspector;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;
use Cbox\LaravelQueueMetrics\Support\MetricsConstants;

/**
 * Database-based implementation of queue metrics repository.
 */
final readonly class DatabaseQueueMetricsRepository implements QueueMetricsRepository
{
    public function __construct(
        private DatabaseMetricsStore $store,
        private QueueInspector $queueInspector,
    ) {}

    /**
     * @return array{depth: int, pending: int, scheduled: int, reserved: int, oldest_job_age: int}
     */
    public function getQueueState(string $connection, string $queue): array
    {
        $depth = $this->queueInspector->getQueueDepth($connection, $queue);

        return [
            'depth' => $depth->totalJobs(),
            'pending' => $depth->pendingJobs,
            'scheduled' => $depth->delayedJobs,
            'reserved' => $depth->reservedJobs,
            'oldest_job_age' => (int) ($depth->secondsOldestPendingJob() ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function recordSnapshot(
        string $connection,
        string $queue,
        array $metrics,
    ): void {
        $key = $this->store->key('queue_snapshot', $connection, $queue);
        $timestampKey = $this->store->key('queue_snapshots', $connection, $queue);

        $driver = $this->store->driver();
        $now = Carbon::now();
        $ttl = $this->store->getTtl('aggregated');

        // Store latest snapshot
        $driver->setHash($key, array_merge($metrics, [
            'recorded_at' => $now->timestamp,
        ]), $ttl);

        // Add to time-series (sorted set)
        $driver->addToSortedSet($timestampKey, [
            json_encode($metrics, JSON_THROW_ON_ERROR) => (int) $now->timestamp,
        ], $ttl);

        // Keep only recent snapshots (last 1000)
        $driver->removeSortedSetByRank($timestampKey, 0, -1001);
    }

    /**
     * Return only the fields the stored snapshot actually contains.
     *
     * The snapshot writer records throughput and failure data, never queue
     * depth. Zero-defaulting the absent depth fields here let a stale zero
     * overwrite the live queue state in getQueueMetrics(), reporting an
     * empty queue while a real backlog existed.
     *
     * @return array<string, mixed>
     */
    public function getLatestMetrics(string $connection, string $queue): array
    {
        $key = $this->store->key('queue_snapshot', $connection, $queue);
        $driver = $this->store->driver();

        /** @var array<string, string> */
        $data = $driver->getHash($key) ?: [];

        if (empty($data)) {
            return [];
        }

        $metrics = [];

        foreach (['depth', 'pending', 'scheduled', 'reserved', 'oldest_job_age', 'active_workers'] as $field) {
            if (array_key_exists($field, $data)) {
                $metrics[$field] = (int) $data[$field];
            }
        }

        foreach (['throughput_per_minute', 'avg_duration', 'failure_rate', 'utilization_rate'] as $field) {
            if (array_key_exists($field, $data)) {
                $metrics[$field] = (float) $data[$field];
            }
        }

        if (isset($data['recorded_at'])) {
            $metrics['recorded_at'] = Carbon::createFromTimestamp((int) $data['recorded_at']);
        }

        return $metrics;
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

        // Check if health score changed significantly and dispatch event
        $previousScore = $this->getPreviousHealthScore($connection, $queue);
        if ($previousScore !== null) {
            $scoreChange = abs($score - $previousScore);
            $threshold = MetricsConstants::HEALTH_SCORE_CHANGE_THRESHOLD;

            if ($scoreChange >= $threshold) {
                HealthScoreChanged::dispatch(
                    $connection,
                    $queue,
                    round($score, 2),
                    round($previousScore, 2),
                    $status
                );
            }
        }

        // Store current score for next comparison
        $this->storeCurrentHealthScore($connection, $queue, $score);

        return ['status' => $status, 'score' => $score];
    }

    /**
     * List all discovered queues using discovery set.
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    public function listQueues(): array
    {
        $key = $this->store->key('discovery', 'queues');
        $members = $this->store->driver()->getSetMembers($key);

        $queues = [];
        foreach ($members as $member) {
            // Parse "connection:queue" format
            $parts = explode(':', $member, 2);
            if (count($parts) === 2) {
                $queues[] = [
                    'connection' => $parts[0],
                    'queue' => $parts[1],
                ];
            }
        }

        return $queues;
    }

    /**
     * Register queue in discovery set (push-based tracking).
     */
    public function markQueueDiscovered(string $connection, string $queue): void
    {
        $key = $this->store->key('discovery', 'queues');
        $ttl = $this->store->getTtl('aggregated');

        $this->store->driver()->addToSet($key, ["{$connection}:{$queue}"], $ttl);
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->store->key('queue_snapshot', '*', '*');
        $keys = $this->scanHashKeys($pattern);
        $driver = $this->store->driver();
        $deleted = 0;

        foreach ($keys as $key) {
            $recordedAt = $driver->getHashField($key, 'recorded_at');

            if ($recordedAt === null || $recordedAt === false) {
                continue;
            }

            $recordedAtInt = is_numeric($recordedAt) ? (int) $recordedAt : 0;
            $age = (int) Carbon::now()->timestamp - $recordedAtInt;

            if ($age > $olderThanSeconds) {
                $driver->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @param  array<string, mixed>  $metrics
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
     * Scan hash keys matching a pattern.
     *
     * Unlike scanKeys on the store (which scans MetricsKey), this method
     * scans MetricsHash for keys matching the given LIKE pattern.
     *
     * @return array<int, string>
     */
    private function scanHashKeys(string $pattern): array
    {
        $sqlPattern = str_replace(['%'], ['\\%'], $pattern);
        $sqlPattern = str_replace(['*', '?'], ['%', '_'], $sqlPattern);

        /** @var array<int, string> */
        return MetricsHash::notExpired()
            ->where('key', 'like', $sqlPattern)
            ->pluck('key')
            ->all();
    }

    /**
     * Get previous health score for comparison.
     */
    private function getPreviousHealthScore(string $connection, string $queue): ?float
    {
        $key = $this->store->key('health_score', $connection, $queue);
        $score = $this->store->driver()->get($key);

        if ($score === null || $score === false) {
            return null;
        }

        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * Store current health score for next comparison.
     */
    private function storeCurrentHealthScore(string $connection, string $queue, float $score): void
    {
        $key = $this->store->key('health_score', $connection, $queue);
        $ttl = 3600; // Keep for 1 hour
        $this->store->driver()->set($key, (string) $score, $ttl);
    }
}
