<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsCompleted;
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

        // Calculate duration
        $startTime = $payload['pushedAt'] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        // Get system metrics from ProcessMetrics tracker
        $trackerId = "job_{$jobId}";
        $metricsResult = ProcessMetrics::stop($trackerId);

        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            // Use peak memory (maximum RSS during job execution, includes children)
            $memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;

            // Calculate CPU time from delta (actual usage during job, not cumulative)
            // delta->cpuUsagePercentage() returns percentage (0-100+)
            // Multiply by duration to get total CPU seconds, then convert to ms
            $cpuUsagePercent = $metrics->delta->cpuUsagePercentage();
            $durationSeconds = $metrics->delta->durationSeconds;
            $cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;
        }

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';

        $jobClass = $payload['displayName'] ?? 'UnknownJob';

        $this->recordJobCompletion->execute(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            durationMs: $durationMs,
            memoryMb: $memoryMb,
            cpuTimeMs: $cpuTimeMs,
            hostname: $hostname,
        );

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
        );

        // Record worker heartbeat with IDLE state (job completed)
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

    private function getWorkerId(): string
    {
        return HorizonDetector::generateWorkerId();
    }
}
