<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\LaravelQueueMetrics\Utilities\MemoryLimitParser;
use Cbox\SystemMetrics\ProcessMetrics;
use Illuminate\Queue\Events\JobProcessed;

/**
 * Listen for successfully processed jobs.
 */
final readonly class JobProcessedListener
{
    public function __construct(
        private RecordJobCompletionAction $recordJobCompletion,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobProcessed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        // Skip performance metrics for debounced (superseded) jobs.
        // The JobDebouncedListener already recorded the debounce counter.
        if (DebouncedJobTracker::wasDebounced($jobId)) {
            return;
        }

        // Calculate duration
        $startTime = $payload['pushedAt'] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        // Get system metrics from ProcessMetrics tracker
        $trackerId = "job_{$jobId}";
        $metricsResult = ProcessMetrics::stop($trackerId);

        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $memoryIncrementalMb = $memoryMb;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Memory: peak RSS is the capacity-planning metric
            $peakMemoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;
            $startMemoryMb = JobMemorySnapshotCache::get($jobId);

            // Incremental allocation is the differentiation metric
            if ($startMemoryMb !== null) {
                $memoryIncrementalMb = max(0.0, $peakMemoryMb - $startMemoryMb);
            } else {
                $memoryIncrementalMb = $peakMemoryMb; // fallback for missing baseline
            }

            $memoryMb = $peakMemoryMb;

            // CPU time: delta between cumulative CPU times at end vs start
            $endCpuTimes = $metrics->current->cpuTimes;
            $endCpuTimeMs = (float) ($endCpuTimes->user + $endCpuTimes->system);
            $startCpuTimeMs = JobCpuSnapshotCache::get($jobId);

            if ($startCpuTimeMs !== null) {
                $cpuTimeMs = max(0.0, $endCpuTimeMs - $startCpuTimeMs);
            }
        }

        JobCpuSnapshotCache::forget($jobId);
        JobMemorySnapshotCache::forget($jobId);

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';

        $jobClass = $payload['displayName'] ?? 'UnknownJob';

        if (config('queue-metrics.persistence.enabled', true)) {
            $this->recordJobCompletion->execute(
                jobId: $jobId,
                jobClass: $jobClass,
                connection: $connection,
                queue: $queue,
                durationMs: $durationMs,
                memoryMb: $memoryMb,
                memoryIncrementalMb: $memoryIncrementalMb,
                cpuTimeMs: $cpuTimeMs,
                hostname: $hostname,
            );
        }

        // Fire per-job metrics event for downstream consumers (e.g., queue-monitor)
        JobMetricsCompleted::dispatch(
            $jobId,
            $jobClass,
            $connection,
            $queue,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $hostname,
            MemoryLimitParser::getCurrentLimitMb(),
            $memoryIncrementalMb,
        );

        // Record worker heartbeat with IDLE state (job completed)
        if (config('queue-metrics.persistence.enabled', true)) {
            $workerId = $this->getWorkerId();
            $this->recordWorkerHeartbeat->execute(
                workerId: $workerId,
                connection: $connection,
                queue: $queue,
                state: WorkerState::IDLE,
                currentJobId: null,
                currentJobClass: null,
            );
        }
    }

    private function getWorkerId(): string
    {
        return HorizonDetector::generateWorkerId();
    }
}
