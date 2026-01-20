<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Commands;

use Cbox\LaravelQueueMetrics\Repositories\Contracts\WorkerRepository;
use Illuminate\Console\Command;

/**
 * Clean up stale worker records from metrics storage.
 *
 * Workers become stale when they stop sending heartbeats, typically due to:
 * - Worker crash or termination
 * - Network issues
 * - Server shutdown
 *
 * This command should run periodically (e.g., every minute) to remove
 * stale workers and maintain accurate worker counts and metrics.
 */
final class CleanupStaleWorkersCommand extends Command
{
    protected $signature = 'queue-metrics:cleanup-stale-workers
                            {--threshold= : Age in seconds before worker is considered stale (default: from config)}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clean up stale worker records that have stopped sending heartbeats';

    public function handle(WorkerRepository $workerRepository): int
    {
        if (! config('queue-metrics.enabled', true)) {
            $this->info('Queue metrics are disabled.');

            return self::SUCCESS;
        }

        /** @var int $threshold */
        $threshold = $this->option('threshold') ?? config('queue-metrics.worker_heartbeat.stale_threshold', 60);

        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Checking for stale workers (threshold: {$threshold}s)...");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No workers will be deleted');
        }

        $deleted = $isDryRun ? 0 : $workerRepository->cleanupStaleWorkers($threshold);

        if ($deleted === 0) {
            $this->info('✓ No stale workers found.');
        } else {
            $this->info("✓ Cleaned up {$deleted} stale worker(s).");
        }

        return self::SUCCESS;
    }
}
