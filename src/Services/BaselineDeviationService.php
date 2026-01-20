<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Services;

use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\MetricsConstants;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Detect deviations from baseline metrics.
 *
 * Monitors current job execution metrics and compares them to established
 * baselines. High deviation triggers more frequent baseline recalculation
 * to adapt to changing workload patterns.
 */
final readonly class BaselineDeviationService
{
    public function __construct(
        private BaselineRepository $baselineRepository,
        private JobMetricsRepository $jobMetricsRepository,
        private RedisMetricsStore $redisStore,
    ) {}

    /**
     * Check if current metrics deviate significantly from baseline.
     *
     * @return array{has_deviation: bool, deviation_score: float, threshold: float}
     */
    public function detectDeviation(string $connection, string $queue): array
    {
        if (! config('queue-metrics.baseline.deviation.enabled', true)) {
            return [
                'has_deviation' => false,
                'deviation_score' => 0.0,
                'threshold' => 0.0,
            ];
        }

        $baseline = $this->baselineRepository->getBaseline($connection, $queue);

        if ($baseline === null) {
            return [
                'has_deviation' => false,
                'deviation_score' => 0.0,
                'threshold' => 0.0,
            ];
        }

        // Get recent samples
        $recentSamples = $this->getRecentMetrics($connection, $queue, MetricsConstants::DEFAULT_RECENT_SAMPLES);

        // No recent samples available for comparison
        // @phpstan-ignore-next-line - Defensive check even though getRecentMetrics returns non-empty array
        if (count($recentSamples) === 0) {
            return [
                'has_deviation' => false,
                'deviation_score' => 0.0,
                'threshold' => 0.0,
            ];
        }

        // Calculate deviation score (in standard deviations)
        $deviationScore = $this->calculateDeviationScore($baseline, $recentSamples);

        /** @var float $threshold */
        $threshold = config('queue-metrics.baseline.deviation.threshold', 2.0);
        $hasDeviation = $deviationScore > $threshold;

        return [
            'has_deviation' => $hasDeviation,
            'deviation_score' => round($deviationScore, 2),
            'threshold' => $threshold,
        ];
    }

    /**
     * Calculate deviation score in standard deviations.
     *
     * @param  array{cpu: array<float>, memory: array<float>, duration: array<float>}  $recentSamples
     */
    private function calculateDeviationScore(BaselineData $baseline, array $recentSamples): float
    {
        $cpuDeviation = $this->calculateMetricDeviation(
            $baseline->cpuPercentPerJob,
            $recentSamples['cpu']
        );

        $memoryDeviation = $this->calculateMetricDeviation(
            $baseline->memoryMbPerJob,
            $recentSamples['memory']
        );

        $durationDeviation = $this->calculateMetricDeviation(
            $baseline->avgDurationMs,
            $recentSamples['duration']
        );

        // Return maximum deviation (worst case)
        return max($cpuDeviation, $memoryDeviation, $durationDeviation);
    }

    /**
     * Calculate deviation for a single metric in standard deviations.
     *
     * @param  array<float>  $samples
     */
    private function calculateMetricDeviation(float $baselineValue, array $samples): float
    {
        if (empty($samples) || $baselineValue <= 0) {
            return 0.0;
        }

        $avg = array_sum($samples) / count($samples);
        $variance = $this->calculateVariance($samples, $avg);
        $stdDev = sqrt($variance);

        if ($stdDev <= 0) {
            return 0.0;
        }

        // Deviation in standard deviations
        return abs($avg - $baselineValue) / $stdDev;
    }

    /**
     * Calculate variance for samples.
     *
     * @param  array<float>  $samples
     */
    private function calculateVariance(array $samples, float $mean): float
    {
        $squaredDiffs = array_map(
            fn ($value) => ($value - $mean) ** 2,
            $samples
        );

        return array_sum($squaredDiffs) / count($samples);
    }

    /**
     * Get recent metrics for a queue by sampling from all job classes.
     *
     * @return array{cpu: array<float>, memory: array<float>, duration: array<float>}
     */
    private function getRecentMetrics(string $connection, string $queue, int $limit): array
    {
        // Discover all job classes for this queue by scanning Redis
        $pattern = $this->redisStore->key('jobs', $connection, $queue, '*');
        $keys = $this->redisStore->scanKeys($pattern);

        if (empty($keys)) {
            return [
                'cpu' => [],
                'memory' => [],
                'duration' => [],
            ];
        }

        // Extract unique job classes from keys
        $jobClasses = [];
        $prefix = $this->redisStore->key('jobs', $connection, $queue, '');
        foreach ($keys as $key) {
            $pos = strrpos($key, $prefix);
            if ($pos !== false) {
                $jobClass = substr($key, $pos + strlen($prefix));
                if (! empty($jobClass)) {
                    $jobClasses[] = $jobClass;
                }
            }
        }

        $jobClasses = array_unique($jobClasses);

        // Collect samples from all job classes
        $cpuSamples = [];
        $memorySamples = [];
        $durationSamples = [];

        foreach ($jobClasses as $jobClass) {
            try {
                // Get recent samples from each job class (limit per class to keep it manageable)
                $perClassLimit = max(1, (int) ceil($limit / count($jobClasses)));

                $cpu = $this->jobMetricsRepository->getCpuTimeSamples($jobClass, $connection, $queue, $perClassLimit);
                $memory = $this->jobMetricsRepository->getMemorySamples($jobClass, $connection, $queue, $perClassLimit);
                $duration = $this->jobMetricsRepository->getDurationSamples($jobClass, $connection, $queue, $perClassLimit);

                $cpuSamples = array_merge($cpuSamples, $cpu);
                $memorySamples = array_merge($memorySamples, $memory);
                $durationSamples = array_merge($durationSamples, $duration);
            } catch (\Throwable $e) {
                // Log and skip jobs that can't be retrieved
                logger()->debug('Failed to get recent metrics for job class', [
                    'job_class' => $jobClass,
                    'connection' => $connection,
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        // Take only the most recent $limit samples
        $cpuSamples = array_slice($cpuSamples, -$limit);
        $memorySamples = array_slice($memorySamples, -$limit);
        $durationSamples = array_slice($durationSamples, -$limit);

        return [
            'cpu' => $cpuSamples,
            'memory' => $memorySamples,
            'duration' => $durationSamples,
        ];
    }

    /**
     * Check if baseline recalculation should be triggered due to deviation.
     */
    public function shouldRecalculate(string $connection, string $queue): bool
    {
        $detection = $this->detectDeviation($connection, $queue);

        return $detection['has_deviation'];
    }

    /**
     * Get recommended recalculation interval based on deviation.
     */
    public function getRecommendedInterval(string $connection, string $queue): int
    {
        $baseline = $this->baselineRepository->getBaseline($connection, $queue);

        if ($baseline === null) {
            /** @var int */
            return config('queue-metrics.baseline.intervals.no_baseline', 1);
        }

        $detection = $this->detectDeviation($connection, $queue);

        // If deviation detected, use faster interval
        if ($detection['has_deviation']) {
            /** @var int */
            return config('queue-metrics.baseline.deviation.trigger_interval', 5);
        }

        // Otherwise use confidence-based interval
        return $this->getIntervalByConfidence($baseline->confidenceScore);
    }

    /**
     * Get recalculation interval based on confidence score.
     */
    private function getIntervalByConfidence(float $confidence): int
    {
        /** @var array{no_baseline: int, low_confidence: int, medium_confidence: int, high_confidence: int, very_high_confidence: int} $intervals */
        $intervals = config('queue-metrics.baseline.intervals') ?? [
            'no_baseline' => 1,
            'low_confidence' => 5,
            'medium_confidence' => 10,
            'high_confidence' => 30,
            'very_high_confidence' => 60,
        ];

        return match (true) {
            $confidence >= 0.9 => $intervals['very_high_confidence'],
            $confidence >= 0.7 => $intervals['high_confidence'],
            $confidence >= 0.5 => $intervals['medium_confidence'],
            default => $intervals['low_confidence'],
        };
    }
}
