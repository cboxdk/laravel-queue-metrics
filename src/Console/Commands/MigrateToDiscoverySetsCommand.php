<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Console\Commands;

use Illuminate\Console\Command;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Migrate existing metrics to discovery sets for fast lookup.
 */
final class MigrateToDiscoverySetsCommand extends Command
{
    protected $signature = 'queue-metrics:migrate-discovery';

    protected $description = 'Migrate existing metrics keys to discovery sets for improved performance';

    public function handle(
        RedisMetricsStore $redis,
        QueueMetricsRepository $queueRepo,
        JobMetricsRepository $jobRepo,
    ): int {
        $this->info('Migrating existing metrics to discovery sets...');

        // Scan for existing job metrics
        $jobPattern = $redis->key('jobs', '*', '*', '*');
        $jobKeys = $redis->driver()->scanKeys($jobPattern);

        $this->info('Found ' . count($jobKeys) . ' job metrics keys');

        $jobsAdded = 0;
        foreach ($jobKeys as $key) {
            // Parse key: queue_metrics:jobs:connection:queue:JobClass
            $parts = explode(':', $key);
            if (count($parts) >= 5) {
                $connection = $parts[2];
                $queue = $parts[3];
                $jobClass = implode(':', array_slice($parts, 4));

                $jobRepo->markJobDiscovered($connection, $queue, $jobClass);
                $jobsAdded++;
            }
        }

        $this->info("Added {$jobsAdded} jobs to discovery set");

        // Scan for existing queue snapshots
        $queuePattern = $redis->key('queue_snapshot', '*', '*');
        $queueKeys = $redis->driver()->scanKeys($queuePattern);

        $this->info('Found ' . count($queueKeys) . ' queue snapshot keys');

        $queuesAdded = 0;
        foreach ($queueKeys as $key) {
            // Parse key: queue_metrics:queue_snapshot:connection:queue
            $parts = explode(':', $key);
            if (count($parts) >= 4) {
                $connection = $parts[2];
                $queue = $parts[3];

                $queueRepo->markQueueDiscovered($connection, $queue);
                $queuesAdded++;
            }
        }

        $this->info("Added {$queuesAdded} queues to discovery set");

        // Verify
        $discoveredQueues = $queueRepo->listQueues();
        $discoveredJobs = $jobRepo->listJobs();

        $this->info('');
        $this->info('Migration complete!');
        $this->info("Discovered queues: " . count($discoveredQueues));
        $this->info("Discovered jobs: " . count($discoveredJobs));

        return self::SUCCESS;
    }
}
