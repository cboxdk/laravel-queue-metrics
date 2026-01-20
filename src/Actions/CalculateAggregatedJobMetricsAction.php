<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\AggregatedJobMetricsData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

/**
 * Calculate aggregated metrics for a job class across all queues.
 */
final readonly class CalculateAggregatedJobMetricsAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private CalculateJobMetricsAction $calculateJobMetrics,
    ) {}

    public function execute(string $jobClass): AggregatedJobMetricsData
    {
        // Get all jobs from the discovery set
        $allJobs = $this->repository->listJobs();

        // Filter to find all connection/queue combinations for this job class
        $jobQueues = array_filter($allJobs, fn (array $job): bool => $job['jobClass'] === $jobClass);

        // If no queues found, return empty aggregated metrics
        if (empty($jobQueues)) {
            return new AggregatedJobMetricsData(
                jobClass: $jobClass,
                totalExecutions: 0,
                totalFailures: 0,
                avgDurationMs: 0.0,
                avgMemoryMb: 0.0,
                failureRate: 0.0,
                throughputPerMinute: 0.0,
                byQueue: [],
                calculatedAt: Carbon::now(),
            );
        }

        // Calculate metrics for each connection/queue combination
        $byQueue = [];
        $totalExecutions = 0;
        $totalFailures = 0;
        $weightedDuration = 0.0;
        $weightedMemory = 0.0;
        $totalThroughput = 0.0;

        foreach ($jobQueues as $job) {
            $metrics = $this->calculateJobMetrics->execute(
                $job['jobClass'],
                $job['connection'],
                $job['queue']
            );

            $executions = $metrics->execution->totalProcessed;
            $failures = $metrics->execution->totalFailed;

            // Accumulate totals
            $totalExecutions += $executions;
            $totalFailures += $failures;

            // Weighted averages (weight by number of executions)
            if ($executions > 0) {
                $weightedDuration += $metrics->duration->avg * $executions;
                $weightedMemory += $metrics->memory->avg * $executions;
            }

            $totalThroughput += $metrics->throughput->perMinute;

            // Calculate failure rate for this queue
            $totalJobs = $executions + $failures;
            $failureRate = $totalJobs > 0 ? ($failures / $totalJobs) * 100 : 0.0;

            // Add to by_queue breakdown
            $byQueue[] = [
                'connection' => $job['connection'],
                'queue' => $job['queue'],
                'executions' => $executions,
                'failures' => $failures,
                'avg_duration_ms' => round($metrics->duration->avg, 2),
                'avg_memory_mb' => round($metrics->memory->avg, 2),
                'failure_rate' => round($failureRate, 2),
                'throughput_per_minute' => round($metrics->throughput->perMinute, 2),
            ];
        }

        // Calculate overall weighted averages
        $avgDurationMs = $totalExecutions > 0 ? $weightedDuration / $totalExecutions : 0.0;
        $avgMemoryMb = $totalExecutions > 0 ? $weightedMemory / $totalExecutions : 0.0;

        // Calculate overall failure rate
        $totalJobs = $totalExecutions + $totalFailures;
        $failureRate = $totalJobs > 0 ? ($totalFailures / $totalJobs) * 100 : 0.0;

        return new AggregatedJobMetricsData(
            jobClass: $jobClass,
            totalExecutions: $totalExecutions,
            totalFailures: $totalFailures,
            avgDurationMs: $avgDurationMs,
            avgMemoryMb: $avgMemoryMb,
            failureRate: $failureRate,
            throughputPerMinute: $totalThroughput,
            byQueue: $byQueue,
            calculatedAt: Carbon::now(),
        );
    }
}
