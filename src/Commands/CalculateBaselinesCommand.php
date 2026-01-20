<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Commands;

use Illuminate\Console\Command;
use Cbox\LaravelQueueMetrics\Actions\CalculateBaselinesAction;

/**
 * Calculate baseline metrics for all queues.
 *
 * Baselines are calculated using sliding windows with exponential decay,
 * giving higher weight to recent data. Used for:
 * - Vertical scaling decisions (capacity planning)
 * - Anomaly detection (deviation from baseline)
 * - Performance benchmarking
 *
 * This command is typically scheduled with adaptive intervals based on
 * confidence scores. See config/queue-metrics.php for interval configuration.
 */
final class CalculateBaselinesCommand extends Command
{
    protected $signature = 'queue-metrics:calculate-baselines
                            {--dry-run : Show what would be calculated without actually calculating}
                            {--queue= : Calculate baseline for specific queue only}
                            {--connection= : Calculate baseline for specific connection only}';

    protected $description = 'Calculate baseline metrics for all queues using sliding window analysis';

    public function handle(CalculateBaselinesAction $action): int
    {
        if (! config('queue-metrics.enabled', true)) {
            $this->info('Queue metrics are disabled.');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $specificQueue = is_string($this->option('queue')) ? $this->option('queue') : null;
        $specificConnection = is_string($this->option('connection')) ? $this->option('connection') : null;

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No baselines will be calculated');
        }

        $this->info('Calculating baselines with sliding window analysis...');
        $this->newLine();

        $startTime = microtime(true);

        if ($specificQueue && $specificConnection) {
            // Calculate for specific queue
            $result = $this->calculateSpecificBaseline(
                $action,
                $specificConnection,
                $specificQueue,
                $isDryRun
            );
        } else {
            // Calculate for all queues
            $result = $isDryRun ? $this->simulateCalculation() : $action->execute();
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->displayResults($result, $duration, $isDryRun);

        return self::SUCCESS;
    }

    /**
     * Calculate baseline for a specific queue.
     *
     * @return array{queues_processed: int, baselines_calculated: int, avg_confidence: float}
     */
    private function calculateSpecificBaseline(
        CalculateBaselinesAction $action,
        string $connection,
        string $queue,
        bool $isDryRun,
    ): array {
        if ($isDryRun) {
            $this->info("Would calculate baseline for: {$connection}/{$queue}");

            return [
                'queues_processed' => 1,
                'baselines_calculated' => 0,
                'avg_confidence' => 0.0,
            ];
        }

        $baseline = $action->calculateForQueue($connection, $queue);

        if ($baseline === null) {
            $this->warn("No data available for {$connection}/{$queue}");

            return [
                'queues_processed' => 1,
                'baselines_calculated' => 0,
                'avg_confidence' => 0.0,
            ];
        }

        $this->info("Calculated baseline for {$connection}/{$queue}");
        $this->displayBaselineDetails($baseline);

        return [
            'queues_processed' => 1,
            'baselines_calculated' => 1,
            'avg_confidence' => $baseline->confidenceScore,
        ];
    }

    /**
     * Simulate calculation for dry-run mode.
     *
     * @return array{queues_processed: int, baselines_calculated: int, avg_confidence: float}
     */
    private function simulateCalculation(): array
    {
        $this->info('Would calculate baselines for all discovered queues');
        $this->info('Using configuration:');
        /** @var int $slidingWindow */
        $slidingWindow = config('queue-metrics.baseline.sliding_window_days', 7);
        /** @var float $decayFactor */
        $decayFactor = config('queue-metrics.baseline.decay_factor', 0.1);
        /** @var int $targetSamples */
        $targetSamples = config('queue-metrics.baseline.target_sample_size', 200);

        $this->line('  - Sliding window: '.$slidingWindow.' days');
        $this->line('  - Decay factor: '.$decayFactor);
        $this->line('  - Target samples: '.$targetSamples);

        return [
            'queues_processed' => 0,
            'baselines_calculated' => 0,
            'avg_confidence' => 0.0,
        ];
    }

    /**
     * Display calculation results.
     *
     * @param  array{queues_processed: int, baselines_calculated: int, avg_confidence: float}  $result
     */
    private function displayResults(array $result, float $durationMs, bool $isDryRun): void
    {
        $this->newLine();

        if ($isDryRun) {
            $this->info('✓ Dry run completed');

            return;
        }

        if ($result['baselines_calculated'] === 0) {
            $this->warn('⚠ No baselines calculated (no data available)');

            return;
        }

        $this->info('✓ Baseline calculation completed');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Queues processed', $result['queues_processed']],
                ['Baselines calculated', $result['baselines_calculated']],
                ['Average confidence', $this->formatConfidence($result['avg_confidence'])],
                ['Duration', $durationMs.'ms'],
            ]
        );

        // Provide guidance on next recalculation
        $this->displayNextRecalculationGuidance($result['avg_confidence']);
    }

    /**
     * Display details for a specific baseline.
     */
    private function displayBaselineDetails(\Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData $baseline): void
    {
        $this->newLine();
        $this->line('  CPU per job: '.$baseline->cpuPercentPerJob.'%');
        $this->line('  Memory per job: '.$baseline->memoryMbPerJob.'MB');
        $this->line('  Avg duration: '.$baseline->avgDurationMs.'ms');
        $this->line('  Sample count: '.$baseline->sampleCount);
        $this->line('  Confidence: '.$this->formatConfidence($baseline->confidenceScore));
    }

    /**
     * Format confidence score with visual indicator.
     */
    private function formatConfidence(float $confidence): string
    {
        $percentage = round($confidence * 100);
        $indicator = match (true) {
            $confidence >= 0.9 => '🟢',
            $confidence >= 0.7 => '🟡',
            $confidence >= 0.5 => '🟠',
            default => '🔴',
        };

        return "{$indicator} {$percentage}%";
    }

    /**
     * Display guidance on next recalculation based on confidence.
     */
    private function displayNextRecalculationGuidance(float $avgConfidence): void
    {
        /** @var array{no_baseline: int, low_confidence: int, medium_confidence: int, high_confidence: int, very_high_confidence: int} $intervals */
        $intervals = config('queue-metrics.baseline.intervals') ?? [
            'no_baseline' => 1,
            'low_confidence' => 5,
            'medium_confidence' => 10,
            'high_confidence' => 30,
            'very_high_confidence' => 60,
        ];

        $nextInterval = match (true) {
            $avgConfidence >= 0.9 => $intervals['very_high_confidence'],
            $avgConfidence >= 0.7 => $intervals['high_confidence'],
            $avgConfidence >= 0.5 => $intervals['medium_confidence'],
            default => $intervals['low_confidence'],
        };

        $this->newLine();
        $this->comment('Next recalculation recommended in: '.$nextInterval.' minutes');

        if ($avgConfidence < 0.5) {
            $this->warn('⚠ Low confidence - more data needed for reliable baselines');
        } elseif ($avgConfidence >= 0.9) {
            $this->info('✓ High confidence - baselines are reliable for scaling decisions');
        }
    }
}
