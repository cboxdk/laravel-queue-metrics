<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Repositories;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Contracts\QueueInspector;
use Cbox\LaravelQueueMetrics\Events\HealthScoreChanged;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Support\HealthScoreCalculator;
use Cbox\LaravelQueueMetrics\Support\MetricsConstants;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Redis-based implementation of queue metrics repository.
 */
final readonly class RedisQueueMetricsRepository implements QueueMetricsRepository
{
    public function __construct(
        private RedisMetricsStore $redis,
        private QueueInspector $queueInspector,
        private WorkerHeartbeatRepository $workerHeartbeats,
        private HealthScoreCalculator $healthScore,
    ) {}

    /**
     * @return array{depth: int, pending: int, scheduled: int, reserved: int, oldest_job_age: int}
     */
    public function getQueueState(string $connection, string $queue): array
    {
        return $this->queueInspector->getQueueDepth($connection, $queue)->toQueueStateArray();
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public function recordSnapshot(
        string $connection,
        string $queue,
        array $metrics,
    ): void {
        $key = $this->redis->key('queue_snapshot', $connection, $queue);
        $timestampKey = $this->redis->key('queue_snapshots', $connection, $queue);

        $driver = $this->redis->driver();
        $now = Carbon::now();
        $ttl = $this->redis->getTtl('aggregated');

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
        $key = $this->redis->key('queue_snapshot', $connection, $queue);
        $driver = $this->redis->driver();

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
        $state = $this->queueInspector->getQueueDepth($connection, $queue)->toQueueStateArray();
        $metrics = $this->getLatestMetrics($connection, $queue);

        if (empty($metrics) && $state['depth'] === 0) {
            return ['status' => 'unknown', 'score' => 0.0];
        }

        /*
         * Live state wins over the snapshot, mirroring getQueueMetrics():
         * the depth and age penalties must see the queue as it is now, not
         * as a snapshot writer recorded it. Worker data is only attached
         * when there is a backlog, since that is the only case the
         * no-worker penalty looks at - an empty queue skips the read.
         */
        $scored = array_merge($metrics, $state);

        if ($state['depth'] > 0) {
            $scored['active_workers'] = $this->workerHeartbeats
                ->getActiveWorkers($connection, $queue)
                ->count();
        }

        $score = $this->healthScore->calculate($scored);
        $status = $this->healthScore->status($score);

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
     * List all discovered queues using discovery set (O(N) instead of O(total_keys)).
     *
     * @return array<int, array{connection: string, queue: string}>
     */
    public function listQueues(): array
    {
        $key = $this->redis->key('discovery', 'queues');
        $members = $this->redis->driver()->getSetMembers($key);

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
        $key = $this->redis->key('discovery', 'queues');
        $ttl = $this->redis->getTtl('aggregated');

        $this->redis->driver()->addToSet($key, ["{$connection}:{$queue}"]);
        $this->redis->driver()->expire($key, $ttl);
    }

    public function cleanup(int $olderThanSeconds): int
    {
        $pattern = $this->redis->key('queue_snapshot', '*', '*');
        $keys = $this->scanKeys($pattern);
        $driver = $this->redis->driver();
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
     * @return array<string>
     */
    private function scanKeys(string $pattern): array
    {
        return $this->redis->driver()->scanKeys($pattern);
    }

    /**
     * Get previous health score for comparison.
     */
    private function getPreviousHealthScore(string $connection, string $queue): ?float
    {
        $key = $this->redis->key('health_score', $connection, $queue);
        $score = $this->redis->driver()->get($key);

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
        $key = $this->redis->key('health_score', $connection, $queue);
        $ttl = 3600; // Keep for 1 hour
        $this->redis->driver()->set($key, (string) $score, $ttl);
    }
}
