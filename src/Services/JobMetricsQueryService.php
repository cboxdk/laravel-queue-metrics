<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Services;

use Cbox\LaravelQueueMetrics\Actions\CalculateAggregatedJobMetricsAction;
use Cbox\LaravelQueueMetrics\Actions\CalculateJobMetricsAction;
use Cbox\LaravelQueueMetrics\DataTransferObjects\AggregatedJobMetricsData;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\DataTransferObjects\JobMetricsData;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

/**
 * Service for querying job-specific metrics.
 */
final readonly class JobMetricsQueryService
{
    public function __construct(
        private CalculateJobMetricsAction $calculateJobMetrics,
        private CalculateAggregatedJobMetricsAction $calculateAggregatedJobMetrics,
        private BaselineRepository $baselineRepository,
        private RedisMetricsStore $redisStore,
        private RedisKeyScannerService $keyScanner,
    ) {}

    /**
     * Get metrics for a specific job class.
     */
    public function getJobMetrics(
        string $jobClass,
        string $connection = 'default',
        string $queue = 'default',
    ): JobMetricsData {
        return $this->calculateJobMetrics->execute($jobClass, $connection, $queue);
    }

    /**
     * Get aggregated metrics for a job class across all queues.
     */
    public function getAggregatedJobMetrics(string $jobClass): AggregatedJobMetricsData
    {
        return $this->calculateAggregatedJobMetrics->execute($jobClass);
    }

    /**
     * Get baseline metrics for a specific job class.
     */
    public function getJobClassBaseline(
        string $connection,
        string $queue,
        string $jobClass,
    ): ?BaselineData {
        return $this->baselineRepository->getJobClassBaseline($connection, $queue, $jobClass);
    }

    /**
     * Get all jobs with full metrics including duration, memory, throughput, time windows.
     * Scans both completed jobs (with metrics) and queued jobs (pending processing).
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllJobsWithMetrics(): array
    {
        $jobs = [];

        // Pattern 1: Completed jobs with metrics
        $jobsPattern = $this->redisStore->key('jobs', '*', '*', '*');

        // Pattern 2: Queued jobs (not yet processed)
        $queuedPattern = $this->redisStore->key('queued', '*', '*', '*');

        // Parse keys to extract job class, connection, and queue
        $keyParser = function (string $keyWithoutPrefix): ?array {
            // Parse: {jobs|queued}:connection:queue:JobClass
            $parts = explode(':', $keyWithoutPrefix);
            if (count($parts) < 4) {
                return null;
            }

            $connection = $parts[1];
            $queue = $parts[2];
            $jobClass = implode(':', array_slice($parts, 3));

            return [
                'connection' => $connection,
                'queue' => $queue,
                'jobClass' => $jobClass,
            ];
        };

        $discoveredJobs = $this->keyScanner->scanAndParseKeys($jobsPattern, $queuedPattern, $keyParser);

        // Build comprehensive metrics for each discovered job
        foreach ($discoveredJobs as $jobClass => $jobData) {
            try {
                $metrics = $this->getJobMetrics($jobClass, $jobData['connection'], $jobData['queue']);
                $baseline = $this->getJobClassBaseline($jobData['connection'], $jobData['queue'], $jobClass);

                $jobArray = $metrics->toArray();
                $jobArray['baseline'] = $baseline ? $baseline->toArray() : null;

                $jobs[$jobClass] = $jobArray;
            } catch (\Throwable $e) {
                // Log and skip jobs that can't be retrieved
                logger()->warning('Failed to get job metrics', [
                    'job_class' => $jobClass,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return $jobs;
    }
}
