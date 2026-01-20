<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Illuminate\Console\Command;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;

/**
 * Console command to detect and mark stale workers as crashed.
 */
final class DetectStaleWorkersCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue-metrics:detect-stale-workers
                            {--threshold=60 : Threshold in seconds to consider a worker stale}';

    /**
     * The console command description.
     */
    protected $description = 'Detect and mark stale workers as crashed';

    public function __construct(
        private readonly WorkerMetricsQueryService $metricsService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! config('queue-metrics.enabled', true)) {
            $this->warn('Queue metrics is disabled');

            return self::FAILURE;
        }

        $threshold = (int) $this->option('threshold');

        $this->info("Detecting stale workers (threshold: {$threshold}s)...");

        $markedCount = $this->metricsService->detectStaledWorkers($threshold);

        if ($markedCount > 0) {
            $this->warn("Marked {$markedCount} worker(s) as crashed");
        } else {
            $this->info('No stale workers detected');
        }

        return self::SUCCESS;
    }
}
