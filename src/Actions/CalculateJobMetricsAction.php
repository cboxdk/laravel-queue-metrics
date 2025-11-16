<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\DurationStats;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\FailureInfo;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobExecutionData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\MemoryStats;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\ThroughputStats;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\WindowStats;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Utilities\PercentileCalculator;

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

        return new JobMetricsData(
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
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function calculateExecution(array $metrics): JobExecutionData
    {
        return JobExecutionData::fromArray($metrics);
    }

    /**
     * @param array<string, mixed> $metrics
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
        $perMinute = $this->repository->getThroughput($jobClass, $connection, $queue, 60);
        $perHour = $this->repository->getThroughput($jobClass, $connection, $queue, 3600);
        $perDay = $this->repository->getThroughput($jobClass, $connection, $queue, 86400);

        return new ThroughputStats(
            perMinute: (float) $perMinute,
            perHour: (float) $perHour,
            perDay: (float) $perDay,
        );
    }

    /**
     * @param array<string, mixed> $metrics
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
                throughput: $windowSeconds > 0 ? $jobsProcessed / ($windowSeconds / 60) : 0.0,
            );
        }

        return $stats;
    }
}
