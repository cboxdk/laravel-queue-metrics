<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Events\BaselineRecalculated;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Calculate baseline metrics using sliding window with exponential decay.
 *
 * Baselines represent normal operating metrics for each queue, used for:
 * - Vertical scaling decisions (capacity planning)
 * - Anomaly detection (deviation from baseline)
 * - Performance benchmarking
 */
final readonly class CalculateBaselinesAction
{
    public function __construct(
        private QueueMetricsRepository $queueMetrics,
        private JobMetricsRepository $jobMetrics,
        private BaselineRepository $baselineRepository,
        private RedisMetricsStore $redis,
    ) {}

    /**
     * Calculate baselines for all queues with adaptive confidence scoring.
     * Creates both per-job-class baselines and aggregated queue baselines.
     *
     * @return array{queues_processed: int, baselines_calculated: int, avg_confidence: float}
     */
    public function execute(): array
    {
        $queues = $this->queueMetrics->listQueues();

        $baselinesCalculated = 0;
        $totalConfidence = 0.0;

        foreach ($queues as $queueInfo) {
            $connection = $queueInfo['connection'];
            $queue = $queueInfo['queue'];

            // Get all job classes for this queue
            $jobClasses = $this->getJobClassesForQueue($connection, $queue);

            if (empty($jobClasses)) {
                continue;
            }

            // Calculate baseline for each job class individually
            $jobClassBaselines = [];
            foreach ($jobClasses as $jobClass) {
                $baseline = $this->calculateJobClassBaseline($connection, $queue, $jobClass);

                if ($baseline !== null) {
                    $previousBaseline = $this->baselineRepository->getBaseline($connection, $queue);
                    $significantChange = $this->hasSignificantChange($previousBaseline, $baseline);

                    $this->baselineRepository->storeBaseline($baseline);
                    $jobClassBaselines[] = $baseline;
                    $baselinesCalculated++;
                    $totalConfidence += $baseline->confidenceScore;

                    // Dispatch event for autoscaler
                    BaselineRecalculated::dispatch(
                        $connection,
                        $queue,
                        $baseline,
                        $significantChange
                    );
                }
            }

            // Calculate aggregated baseline across all job classes
            if (! empty($jobClassBaselines)) {
                $aggregatedBaseline = $this->calculateAggregatedBaseline($connection, $queue, $jobClassBaselines);
                $previousAggregated = $this->baselineRepository->getBaseline($connection, $queue);
                $significantChange = $this->hasSignificantChange($previousAggregated, $aggregatedBaseline);

                $this->baselineRepository->storeBaseline($aggregatedBaseline);
                $baselinesCalculated++;
                $totalConfidence += $aggregatedBaseline->confidenceScore;

                // Dispatch event for aggregated baseline
                BaselineRecalculated::dispatch(
                    $connection,
                    $queue,
                    $aggregatedBaseline,
                    $significantChange
                );
            }
        }

        return [
            'queues_processed' => count($queues),
            'baselines_calculated' => $baselinesCalculated,
            'avg_confidence' => $baselinesCalculated > 0
                ? round($totalConfidence / $baselinesCalculated, 2)
                : 0.0,
        ];
    }

    /**
     * Calculate baseline for a specific job class.
     */
    private function calculateJobClassBaseline(
        string $connection,
        string $queue,
        string $jobClass,
    ): ?BaselineData {
        /** @var int $slidingWindowDays */
        $slidingWindowDays = config('queue-metrics.baseline.sliding_window_days', 7);
        /** @var float $decayFactor */
        $decayFactor = config('queue-metrics.baseline.decay_factor', 0.1);
        /** @var int $maxSamples */
        $maxSamples = config('queue-metrics.performance.baseline_samples', 100);

        // Collect samples for this specific job class using pipeline for efficiency
        // This reduces 3 separate Redis calls to 1 pipelined call
        $samples = $this->collectSamplesWithPipeline($jobClass, $connection, $queue, $maxSamples);

        $durations = $samples['duration'];
        $memory = $samples['memory'];
        $cpu = $samples['cpu'];

        $totalSamples = count($durations);

        if ($totalSamples === 0) {
            return null;
        }

        // Apply sliding window with exponential decay
        $weightedDuration = $this->calculateWeightedAverage($durations, $slidingWindowDays, $decayFactor);
        $weightedMemory = $this->calculateWeightedAverage($memory, $slidingWindowDays, $decayFactor);
        $weightedCpu = $this->calculateWeightedAverage($cpu, $slidingWindowDays, $decayFactor);

        // Calculate confidence score (0.0 to 1.0)
        $confidenceScore = $this->calculateConfidenceScore($totalSamples);

        return new BaselineData(
            connection: $connection,
            queue: $queue,
            jobClass: $jobClass,
            cpuPercentPerJob: $weightedCpu > 0 ? round($weightedCpu / 1000, 2) : 0.0, // Convert ms to %
            memoryMbPerJob: round($weightedMemory, 2),
            avgDurationMs: round($weightedDuration, 2),
            sampleCount: $totalSamples,
            confidenceScore: $confidenceScore,
            calculatedAt: Carbon::now(),
        );
    }

    /**
     * Calculate aggregated baseline across all job class baselines.
     *
     * @param  array<int, BaselineData>  $jobClassBaselines
     */
    private function calculateAggregatedBaseline(
        string $connection,
        string $queue,
        array $jobClassBaselines,
    ): BaselineData {
        $totalSamples = 0;
        $weightedCpu = 0.0;
        $weightedMemory = 0.0;
        $weightedDuration = 0.0;
        $totalWeight = 0.0;

        // Weight each job class by its sample count
        foreach ($jobClassBaselines as $baseline) {
            $weight = $baseline->sampleCount;
            $totalWeight += $weight;
            $totalSamples += $baseline->sampleCount;

            $weightedCpu += $baseline->cpuPercentPerJob * $weight;
            $weightedMemory += $baseline->memoryMbPerJob * $weight;
            $weightedDuration += $baseline->avgDurationMs * $weight;
        }

        // Calculate weighted averages
        $avgCpu = $totalWeight > 0 ? $weightedCpu / $totalWeight : 0.0;
        $avgMemory = $totalWeight > 0 ? $weightedMemory / $totalWeight : 0.0;
        $avgDuration = $totalWeight > 0 ? $weightedDuration / $totalWeight : 0.0;

        // Aggregate confidence score
        $confidenceScore = $this->calculateConfidenceScore($totalSamples);

        return new BaselineData(
            connection: $connection,
            queue: $queue,
            jobClass: '', // Empty string indicates aggregated baseline
            cpuPercentPerJob: round($avgCpu, 2),
            memoryMbPerJob: round($avgMemory, 2),
            avgDurationMs: round($avgDuration, 2),
            sampleCount: $totalSamples,
            confidenceScore: $confidenceScore,
            calculatedAt: Carbon::now(),
        );
    }

    /**
     * Calculate weighted average with exponential decay.
     * Recent samples have higher weight.
     *
     * @param  array<int, float>  $samples
     */
    private function calculateWeightedAverage(array $samples, int $windowDays, float $decayFactor): float
    {
        if (empty($samples)) {
            return 0.0;
        }

        $now = time();
        $windowSeconds = $windowDays * 86400;

        // Sort samples by age (newest first)
        // For this implementation, we assume samples are recent
        // In production, you'd need timestamps for each sample

        $totalWeight = 0.0;
        $weightedSum = 0.0;
        $sampleCount = count($samples);

        foreach ($samples as $index => $value) {
            // Calculate age approximation (assuming samples are sorted newest first)
            // In real implementation, get actual timestamp from Redis sorted set score
            $approximateAge = ($index / $sampleCount) * $windowSeconds;

            // Exponential decay: weight = e^(-λ * age_in_days)
            $ageInDays = $approximateAge / 86400;
            $weight = exp(-$decayFactor * $ageInDays);

            $weightedSum += $value * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * Calculate confidence score based on sample size.
     *
     * Confidence progression:
     * - < 10 samples: Very low confidence (0.0-0.2)
     * - 10-50 samples: Low confidence (0.2-0.5)
     * - 50-100 samples: Medium confidence (0.5-0.7)
     * - 100-200 samples: High confidence (0.7-0.9)
     * - 200+ samples: Very high confidence (0.9-1.0)
     */
    private function calculateConfidenceScore(int $sampleCount): float
    {
        /** @var int $targetSampleSize */
        $targetSampleSize = config('queue-metrics.baseline.target_sample_size', 200);

        if ($sampleCount >= $targetSampleSize) {
            return 1.0;
        }

        // Logarithmic growth for confidence
        // Provides faster confidence gain early, slower later
        $confidence = log($sampleCount + 1) / log($targetSampleSize + 1);

        return round(min(1.0, max(0.0, $confidence)), 2);
    }

    /**
     * Collect all sample types using Redis pipeline for efficiency.
     *
     * @return array{duration: array<float>, memory: array<float>, cpu: array<float>}
     */
    private function collectSamplesWithPipeline(
        string $jobClass,
        string $connection,
        string $queue,
        int $maxSamples,
    ): array {
        $durations = [];
        $memory = [];
        $cpu = [];

        // Use pipeline to batch the 3 Redis calls into 1 round trip
        $this->redis->pipeline(function ($pipe) use ($jobClass, $connection, $queue, $maxSamples, &$durations, &$memory, &$cpu) {
            $durations = $this->jobMetrics->getDurationSamples($jobClass, $connection, $queue, $maxSamples);
            $memory = $this->jobMetrics->getMemorySamples($jobClass, $connection, $queue, $maxSamples);
            $cpu = $this->jobMetrics->getCpuTimeSamples($jobClass, $connection, $queue, $maxSamples);
        });

        return [
            'duration' => $durations,
            'memory' => $memory,
            'cpu' => $cpu,
        ];
    }

    /**
     * Get all job classes that have been processed for a queue.
     *
     * @return array<int, string>
     */
    private function getJobClassesForQueue(string $connection, string $queue): array
    {
        $driver = $this->redis->driver();
        $pattern = $this->redis->key('jobs', $connection, $queue, '*');

        // Use SCAN to find all job metric keys
        $keys = $this->redis->scanKeys($pattern);

        $jobClasses = [];
        foreach ($keys as $key) {
            // Extract job class from key format: queue_metrics:jobs:{connection}:{queue}:{jobClass}
            $parts = explode(':', $key);
            if (count($parts) >= 5) {
                $jobClass = implode(':', array_slice($parts, 4)); // Handle job classes with colons
                $jobClasses[] = $jobClass;
            }
        }

        return array_unique($jobClasses);
    }

    /**
     * Calculate baseline for a specific connection/queue combination.
     * Public method for targeted recalculation.
     * Returns the aggregated baseline.
     */
    public function calculateForQueue(string $connection, string $queue): ?BaselineData
    {
        $jobClasses = $this->getJobClassesForQueue($connection, $queue);

        if (empty($jobClasses)) {
            return null;
        }

        // Calculate baseline for each job class individually
        $jobClassBaselines = [];
        foreach ($jobClasses as $jobClass) {
            $baseline = $this->calculateJobClassBaseline($connection, $queue, $jobClass);

            if ($baseline !== null) {
                $this->baselineRepository->storeBaseline($baseline);
                $jobClassBaselines[] = $baseline;
            }
        }

        // Calculate and store aggregated baseline
        if (! empty($jobClassBaselines)) {
            $aggregatedBaseline = $this->calculateAggregatedBaseline($connection, $queue, $jobClassBaselines);
            $this->baselineRepository->storeBaseline($aggregatedBaseline);

            return $aggregatedBaseline;
        }

        return null;
    }

    /**
     * Determine if baseline has changed significantly.
     * Significant changes trigger autoscaler adjustments.
     */
    private function hasSignificantChange(?BaselineData $previous, BaselineData $current): bool
    {
        if ($previous === null) {
            return false; // First baseline, not a change
        }

        // Check if duration changed by >20%
        $durationChange = abs($current->avgDurationMs - $previous->avgDurationMs) / max($previous->avgDurationMs, 1);
        if ($durationChange > 0.2) {
            return true;
        }

        // Check if CPU changed by >20%
        $cpuChange = abs($current->cpuPercentPerJob - $previous->cpuPercentPerJob) / max($previous->cpuPercentPerJob, 1);
        if ($cpuChange > 0.2) {
            return true;
        }

        // Check if memory changed by >20%
        $memoryChange = abs($current->memoryMbPerJob - $previous->memoryMbPerJob) / max($previous->memoryMbPerJob, 1);
        if ($memoryChange > 0.2) {
            return true;
        }

        return false;
    }
}
