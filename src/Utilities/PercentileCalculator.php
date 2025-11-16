<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Utilities;

/**
 * Calculate percentiles from a dataset.
 */
final class PercentileCalculator
{
    /**
     * Calculate percentile from sorted or unsorted data.
     *
     * @param array<int, float> $values
     */
    public function calculate(array $values, float $percentile): float
    {
        if (empty($values)) {
            return 0.0;
        }

        sort($values);

        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return $values[$lower];
        }

        $weight = $index - $lower;

        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }

    /**
     * Calculate multiple percentiles at once.
     *
     * @param array<int, float> $values
     * @param array<int, float> $percentiles
     * @return array<string, float>
     */
    public function calculateMultiple(array $values, array $percentiles): array
    {
        if (empty($values)) {
            return array_fill_keys(
                array_map(fn ($p) => "p{$p}", $percentiles),
                0.0
            );
        }

        sort($values);

        $results = [];
        foreach ($percentiles as $percentile) {
            $key = "p" . (int) $percentile;
            $results[$key] = $this->calculate($values, $percentile);
        }

        return $results;
    }

    /**
     * Calculate standard deviation.
     *
     * @param array<int, float> $values
     */
    public function standardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDifferences = array_map(
            fn ($value) => ($value - $mean) ** 2,
            $values
        );

        $variance = array_sum($squaredDifferences) / count($values);

        return sqrt($variance);
    }
}
