<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

/**
 * Calculate aggregated queue-level metrics from job-level metrics.
 *
 * This action aggregates metrics across all job classes for a given queue,
 * calculating weighted averages and totals for throughput, duration, and failure rates.
 */
final readonly class CalculateQueueMetricsAction
{
    public function __construct(
        private JobMetricsRepository $jobRepository,
        private QueueMetricsRepository $queueRepository,
    ) {}

    /**
     * Calculate and store aggregated metrics for a specific queue.
     */
    public function execute(string $connection, string $queue): void
    {
        // Get all jobs for this queue
        $allJobs = $this->jobRepository->listJobs();
        $queueJobs = array_filter($allJobs, fn ($job) => $job['connection'] === $connection && $job['queue'] === $queue);

        if (empty($queueJobs)) {
            // No jobs found for this queue - record zero metrics
            $this->queueRepository->recordSnapshot($connection, $queue, [
                'throughput_per_minute' => 0.0,
                'avg_duration' => 0.0,
                'failure_rate' => 0.0,
            ]);

            return;
        }

        // Aggregate metrics across all job classes
        $totalProcessed = 0;
        $totalFailed = 0;
        $totalDurationMs = 0.0;
        $lastProcessedAt = null;

        foreach ($queueJobs as $job) {
            $jobClass = $job['jobClass'];
            $metrics = $this->jobRepository->getMetrics($jobClass, $connection, $queue);

            $totalProcessed += is_int($metrics['total_processed']) ? $metrics['total_processed'] : 0;
            $totalFailed += is_int($metrics['total_failed']) ? $metrics['total_failed'] : 0;
            $totalDurationMs += is_float($metrics['total_duration_ms']) || is_int($metrics['total_duration_ms'])
                ? (float) $metrics['total_duration_ms']
                : 0.0;

            if ($metrics['last_processed_at'] instanceof \Carbon\Carbon) {
                if ($lastProcessedAt === null || $metrics['last_processed_at']->greaterThan($lastProcessedAt)) {
                    $lastProcessedAt = $metrics['last_processed_at'];
                }
            }
        }

        // Calculate aggregated metrics
        $avgDuration = $totalProcessed > 0 ? $totalDurationMs / $totalProcessed : 0.0;
        $failureRate = ($totalProcessed + $totalFailed) > 0
            ? ($totalFailed / ($totalProcessed + $totalFailed)) * 100.0
            : 0.0;

        // Calculate throughput per minute (jobs completed in last 60 seconds)
        $throughputPerMinute = 0.0;
        foreach ($queueJobs as $job) {
            $jobClass = $job['jobClass'];
            $throughputPerMinute += $this->jobRepository->getThroughput(
                $jobClass,
                $connection,
                $queue,
                60 // last 60 seconds
            );
        }

        // Store aggregated metrics
        $this->queueRepository->recordSnapshot($connection, $queue, [
            'throughput_per_minute' => $throughputPerMinute,
            'avg_duration' => $avgDuration,
            'failure_rate' => $failureRate,
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed,
            'last_processed_at' => $lastProcessedAt?->timestamp,
        ]);
    }

    /**
     * Calculate metrics for all discovered queues.
     */
    public function executeForAllQueues(): int
    {
        $queues = $this->queueRepository->listQueues();
        $count = 0;

        foreach ($queues as $queue) {
            $this->execute($queue['connection'], $queue['queue']);
            $count++;
        }

        return $count;
    }
}
