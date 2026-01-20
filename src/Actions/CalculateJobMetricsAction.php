<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\DurationStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\FailureInfo;
use Cbox\LaravelQueueMetrics\DataTransferObjects\JobExecutionData;
use Cbox\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use Cbox\LaravelQueueMetrics\DataTransferObjects\MemoryStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\ThroughputStats;
use Cbox\LaravelQueueMetrics\DataTransferObjects\WindowStats;
use Cbox\LaravelQueueMetrics\Events\MetricsRecorded;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\MetricsConstants;
use Cbox\LaravelQueueMetrics\Utilities\PercentileCalculator;

/**
 * Calculate comprehensive metrics for a job class.
 */
final readonly class CalculateJobMetricsAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private PercentileCalculator $percentiles,
    ) {}

    public function execute(
        string $jobClass,
        string $connection = 'default',
        string $queue = 'default',
    ): JobMetricsData {
        $metrics = $this->repository->getMetrics($jobClass, $connection, $queue);

        $metricsData = new JobMetricsData(
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            execution: $this->calculateExecution($metrics),
            duration: $this->calculateDuration($jobClass, $connection, $queue, $metrics),
            memory: $this->calculateMemory($jobClass, $connection, $queue),
            throughput: $this->calculateThroughput($jobClass, $connection, $queue),
            failures: $this->calculateFailures($metrics),
            windowStats: $this->calculateWindows($jobClass, $connection, $queue),
            calculatedAt: Carbon::now(),
        );

        // Dispatch event for real-time monitoring
        MetricsRecorded::dispatch($metricsData);

        return $metricsData;
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function calculateExecution(array $metrics): JobExecutionData
    {
        return JobExecutionData::fromArray($metrics);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function calculateDuration(
        string $jobClass,
        string $connection,
        string $queue,
        array $metrics,
    ): DurationStats {
        $samples = $this->repository->getDurationSamples($jobClass, $connection, $queue);

        if (empty($samples)) {
            return new DurationStats(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
        }

        $percentiles = $this->percentiles->calculateMultiple($samples, [50, 95, 99]);

        return new DurationStats(
            avg: array_sum($samples) / count($samples),
            min: min($samples),
            max: max($samples),
            p50: $percentiles['p50'],
            p95: $percentiles['p95'],
            p99: $percentiles['p99'],
            stddev: $this->percentiles->standardDeviation($samples),
        );
    }

    private function calculateMemory(
        string $jobClass,
        string $connection,
        string $queue,
    ): MemoryStats {
        $samples = $this->repository->getMemorySamples($jobClass, $connection, $queue);

        if (empty($samples)) {
            return new MemoryStats(0.0, 0.0, 0.0, 0.0);
        }

        $percentiles = $this->percentiles->calculateMultiple($samples, [95, 99]);

        return new MemoryStats(
            avg: array_sum($samples) / count($samples),
            peak: max($samples),
            p95: $percentiles['p95'],
            p99: $percentiles['p99'],
        );
    }

    private function calculateThroughput(
        string $jobClass,
        string $connection,
        string $queue,
    ): ThroughputStats {
        $perMinute = $this->repository->getThroughput($jobClass, $connection, $queue, MetricsConstants::SECONDS_PER_MINUTE);
        $perHour = $this->repository->getThroughput($jobClass, $connection, $queue, MetricsConstants::SECONDS_PER_HOUR);
        $perDay = $this->repository->getThroughput($jobClass, $connection, $queue, MetricsConstants::SECONDS_PER_DAY);

        return new ThroughputStats(
            perMinute: (float) $perMinute,
            perHour: (float) $perHour,
            perDay: (float) $perDay,
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function calculateFailures(array $metrics): FailureInfo
    {
        return FailureInfo::fromArray($metrics);
    }

    /**
     * @return array<WindowStats>
     */
    private function calculateWindows(
        string $jobClass,
        string $connection,
        string $queue,
    ): array {
        $shortWindows = config('queue-metrics.windows.short', [60, 300, 900]);
        $mediumWindows = config('queue-metrics.windows.medium', [3600]);
        $longWindows = config('queue-metrics.windows.long', [86400]);

        /** @var array<int> */
        $windows = array_merge(
            is_array($shortWindows) ? $shortWindows : [60, 300, 900],
            is_array($mediumWindows) ? $mediumWindows : [3600],
            is_array($longWindows) ? $longWindows : [86400],
        );

        $stats = [];
        foreach ($windows as $windowSeconds) {
            $jobsProcessed = $this->repository->getThroughput(
                $jobClass,
                $connection,
                $queue,
                $windowSeconds
            );

            $stats[] = new WindowStats(
                windowSeconds: $windowSeconds,
                jobsProcessed: $jobsProcessed,
                avgDuration: 0.0, // Would need more complex calculation
                throughput: $windowSeconds > 0 ? $jobsProcessed / ($windowSeconds / MetricsConstants::SECONDS_PER_MINUTE) : 0.0,
            );
        }

        return $stats;
    }
}
