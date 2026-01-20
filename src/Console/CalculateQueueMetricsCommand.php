<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Illuminate\Console\Command;

/**
 * Calculate and update queue-level aggregated metrics.
 *
 * This command aggregates job-level metrics into queue-level metrics including
 * throughput_per_minute, avg_duration, and failure_rate. These metrics are
 * essential for auto-scaling calculations and queue health monitoring.
 */
final class CalculateQueueMetricsCommand extends Command
{
    protected $signature = 'queue-metrics:calculate
                           {--connection= : Calculate metrics only for this connection}
                           {--queue= : Calculate metrics only for this queue (requires --connection)}';

    protected $description = 'Calculate aggregated queue-level metrics from job metrics';

    public function __construct(
        private readonly CalculateQueueMetricsAction $action,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->option('connection');
        $queue = $this->option('queue');

        // Validate options
        if ($queue !== null && $connection === null) {
            $this->error('The --queue option requires --connection to be specified');

            return self::FAILURE;
        }

        try {
            if (is_string($connection) && is_string($queue)) {
                // Calculate for specific queue
                $this->info("Calculating metrics for {$connection}:{$queue}...");
                $this->action->execute($connection, $queue);
                $this->info('✓ Metrics calculated successfully');

                return self::SUCCESS;
            }

            // Calculate for all queues
            $this->info('Calculating metrics for all queues...');
            $count = $this->action->executeForAllQueues();
            $this->info("✓ Metrics calculated for {$count} queue(s)");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to calculate queue metrics: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
