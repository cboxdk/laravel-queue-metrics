<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Illuminate\Console\Command;
use Cbox\LaravelQueueMetrics\Actions\RecordQueueDepthHistoryAction;
use Cbox\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerHeartbeatRepository;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Support\MetricsConstants;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Record historical trend data for analysis.
 */
final class RecordTrendDataCommand extends Command
{
    protected $signature = 'queue-metrics:record-trends';

    protected $description = 'Record queue depth and throughput for trend analysis';

    public function __construct(
        private readonly WorkerHeartbeatRepository $workerHeartbeat,
        private readonly RecordQueueDepthHistoryAction $recordQueueDepth,
        private readonly QueueMetricsQueryService $metricsQuery,
        private readonly RedisMetricsStore $storage,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Recording trend data...');

        $recordedQueues = 0;
        $recordedWorkers = 0;

        // Get all queue depths from metricsQuery (discover from Redis keys)
        try {
            /** @var array<string, array{connection: string, queue: string, depth: int, pending: int}> $allQueues */
            $allQueues = $this->metricsQuery->getAllQueuesWithMetrics();

            foreach ($allQueues as $queueData) {
                $this->recordQueueDepth->execute(
                    $queueData['connection'],
                    $queueData['queue'],
                    $queueData['depth']
                );
                $recordedQueues++;
            }
        } catch (\Exception $e) {
            $this->warn("Failed to record queue depths - {$e->getMessage()}");
        }

        // Throughput is calculated from queue metrics
        // No additional recording needed here

        // Record worker efficiency metrics
        try {
            $workers = $this->workerHeartbeat->getActiveWorkers();

            if ($workers->isNotEmpty()) {
                $totalBusyTime = 0.0;
                $totalIdleTime = 0.0;
                $totalMemory = 0.0;
                $totalCpu = 0.0;

                foreach ($workers as $worker) {
                    $totalBusyTime += $worker->busyTimeSeconds;
                    $totalIdleTime += $worker->idleTimeSeconds;
                    $totalMemory += $worker->memoryUsageMb;
                    $totalCpu += $worker->cpuUsagePercent;
                }

                $workerCount = $workers->count();
                $totalTime = $totalBusyTime + $totalIdleTime;
                $efficiency = $totalTime > 0 ? ($totalBusyTime / $totalTime) * 100 : 0;

                $redis = $this->storage->driver();
                $key = $this->storage->key('worker_efficiency_history');
                $now = time();

                $dataPoint = json_encode([
                    'timestamp' => $now,
                    'efficiency' => $efficiency,
                    'avg_memory_mb' => $totalMemory / $workerCount,
                    'avg_cpu_percent' => $totalCpu / $workerCount,
                ], JSON_THROW_ON_ERROR);

                $redis->addToSortedSet($key, [$dataPoint => (float) $now]);

                // Cleanup old data
                $cutoff = $now - (86400 * 7); // Keep 7 days
                $redis->removeSortedSetByScore($key, '-inf', (string) $cutoff);

                $recordedWorkers = $workerCount;

                // Check if efficiency changed significantly and dispatch event
                $previousEfficiency = $this->getPreviousEfficiency($redis, $key);
                if ($previousEfficiency !== null) {
                    $changePercentage = abs($efficiency - $previousEfficiency);
                    $threshold = MetricsConstants::EFFICIENCY_CHANGE_THRESHOLD;

                    if ($changePercentage >= $threshold) {
                        $idleWorkers = 0;
                        foreach ($workers as $worker) {
                            $workerTime = $worker->busyTimeSeconds + $worker->idleTimeSeconds;
                            if ($workerTime > 0 && ($worker->idleTimeSeconds / $workerTime) > 0.8) {
                                $idleWorkers++;
                            }
                        }

                        WorkerEfficiencyChanged::dispatch(
                            round($efficiency, 2),
                            round($previousEfficiency, 2),
                            round($changePercentage, 2),
                            $workerCount - $idleWorkers,
                            $idleWorkers
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to record worker efficiency - {$e->getMessage()}");
        }

        $this->info("Recorded trends for {$recordedQueues} queues and {$recordedWorkers} workers");

        return self::SUCCESS;
    }

    /**
     * Get previous efficiency from history for comparison.
     */
    private function getPreviousEfficiency(RedisMetricsStore $redis, string $key): ?float
    {
        // Get all data points and take second-to-last (previous efficiency)
        /** @var array<string> $dataPoints */
        $dataPoints = $redis->getSortedSetByScore($key, '-inf', '+inf');

        if (count($dataPoints) < 2) {
            return null; // Need at least 2 data points to compare
        }

        // Get second-to-last element
        $previousDataPoint = $dataPoints[count($dataPoints) - 2];
        $data = json_decode($previousDataPoint, true);

        if (! is_array($data) || ! isset($data['efficiency'])) {
            return null;
        }

        $efficiency = $data['efficiency'];

        return is_numeric($efficiency) ? (float) $efficiency : null;
    }
}
