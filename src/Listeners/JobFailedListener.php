<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\LaravelQueueMetrics\Utilities\MemoryLimitParser;
use Cbox\SystemMetrics\ProcessMetrics;
use Illuminate\Queue\Events\JobFailed;

/**
 * Listen for failed jobs.
 */
final readonly class JobFailedListener
{
    public function __construct(
        private RecordJobFailureAction $recordJobFailure,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobFailed $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        // Calculate duration
        $startTime = $payload['pushedAt'] ?? microtime(true);
        $durationMs = (microtime(true) - $startTime) * 1000;

        // Stop process metrics tracker (started in JobProcessingListener)
        $trackerId = "job_{$jobId}";
        $metricsResult = ProcessMetrics::stop($trackerId);

        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $cpuTimeMs = 0.0;

        if ($metricsResult->isSuccess()) {
            $metrics = $metricsResult->getValue();

            $memoryMb = $metrics->peak->memoryRssBytes / 1024 / 1024;

            $cpuUsagePercent = $metrics->delta->cpuUsagePercentage();
            $durationSeconds = $metrics->delta->durationSeconds;
            $cpuTimeMs = ($cpuUsagePercent / 100.0) * $durationSeconds * 1000.0;
        }

        $connection = $event->connectionName;
        $queue = $job->getQueue();
        $hostname = gethostname() ?: 'unknown';
        $jobClass = $payload['displayName'] ?? 'UnknownJob';

        $this->recordJobFailure->execute(
            jobId: $jobId,
            jobClass: $jobClass,
            connection: $connection,
            queue: $queue,
            exception: $event->exception,
            hostname: $hostname,
        );

        // Fire per-job metrics event for downstream consumers (e.g., queue-monitor)
        $exceptionMessage = $event->exception->getMessage().' in '.$event->exception->getFile().':'.$event->exception->getLine();

        JobMetricsFailed::dispatch(
            $jobId,
            $jobClass,
            $connection,
            $queue,
            $durationMs,
            $memoryMb,
            $cpuTimeMs,
            $exceptionMessage,
            $hostname,
            MemoryLimitParser::getCurrentLimitMb(),
        );

        // Record worker heartbeat with IDLE state (job failed, worker ready for next job)
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
