<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Scores queue health from a merged live-state + snapshot metrics array.
 *
 * Shared by the Database and Redis repositories so the scoring rules cannot
 * drift between the two implementations.
 */
final readonly class HealthScoreCalculator
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function calculate(array $metrics): float
    {
        $score = 100.0;

        $depthValue = $metrics['depth'] ?? 0;
        $depth = is_numeric($depthValue) ? (int) $depthValue : 0;
        if ($depth > 100) {
            $score -= min(30, ($depth - 100) / 10);
        }

        $oldestAgeValue = $metrics['oldest_job_age'] ?? 0;
        $oldestAge = is_numeric($oldestAgeValue) ? (int) $oldestAgeValue : 0;
        if ($oldestAge > 300) {
            $score -= min(30, ($oldestAge - 300) / 60);
        }

        $failureRateValue = $metrics['failure_rate'] ?? 0.0;
        $failureRate = is_numeric($failureRateValue) ? (float) $failureRateValue : 0.0;
        $score -= min(20, $failureRate);

        /*
         * The no-worker penalty only fires when worker data is actually
         * present in the array: an absent key means "unknown", and unknown
         * must not score like a confirmed zero-worker outage.
         */
        if (array_key_exists('active_workers', $metrics)) {
            $activeWorkersValue = $metrics['active_workers'];
            $activeWorkers = is_numeric($activeWorkersValue) ? (int) $activeWorkersValue : 0;
            if ($activeWorkers === 0 && $depth > 0) {
                $score -= 20;
            }
        }

        return max(0.0, $score);
    }

    public function status(float $score): string
    {
        return match (true) {
            $score >= 80.0 => 'healthy',
            $score >= 50.0 => 'warning',
            default => 'critical',
        };
    }
}
