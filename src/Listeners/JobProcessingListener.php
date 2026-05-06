<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Listeners;

use Cbox\LaravelQueueMetrics\Actions\RecordJobStartAction;
use Cbox\LaravelQueueMetrics\Actions\RecordWorkerHeartbeatAction;
use Cbox\LaravelQueueMetrics\Enums\WorkerState;
use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;
use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;
use Cbox\LaravelQueueMetrics\Utilities\HorizonDetector;
use Cbox\LaravelQueueMetrics\Utilities\MemoryConverter;
use Cbox\SystemMetrics\ProcessMetrics;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Listen for jobs starting to process.
 */
final readonly class JobProcessingListener
{
    public function __construct(
        private RecordJobStartAction $recordJobStart,
        private RecordWorkerHeartbeatAction $recordWorkerHeartbeat,
    ) {}

    public function handle(JobProcessing $event): void
    {
        $job = $event->job;
        $payload = $job->payload();
        $jobId = (string) $job->getJobId();

        $pid = getmypid();
        if ($pid !== false) {
            ProcessMetrics::start(
                pid: $pid,
                trackerId: "job_{$jobId}",
                includeChildren: true
            );

            $snapshotResult = ProcessMetrics::snapshot($pid);
            if ($snapshotResult->isSuccess()) {
                $resources = $snapshotResult->getValue()->resources;
                $cpuTimes = $resources->cpuTimes;
                $totalCpuTimeMs = (float) ($cpuTimes->user + $cpuTimes->system);
                JobCpuSnapshotCache::store($jobId, $totalCpuTimeMs);
                JobMemorySnapshotCache::store($jobId, MemoryConverter::bytesToMegabytes($resources->memoryRssBytes));
            }
        }

        $jobClass = $payload['displayName'] ?? 'UnknownJob';
        $connection = $event->connectionName;
        $queue = $job->getQueue();

        if (config('queue-metrics.persistence.enabled', true)) {
            try {
                $this->recordJobStart->execute(
                    jobId: $jobId,
                    jobClass: $jobClass,
                    connection: $connection,
                    queue: $queue,
                );

                $workerId = HorizonDetector::generateWorkerId();
                $this->recordWorkerHeartbeat->execute(
                    workerId: $workerId,
                    connection: $connection,
                    queue: $queue,
                    state: WorkerState::BUSY,
                    currentJobId: $jobId,
                    currentJobClass: $jobClass,
                );
            } catch (\Throwable $e) {
                ProcessMetrics::stop("job_{$jobId}");
                JobCpuSnapshotCache::forget($jobId);
                JobMemorySnapshotCache::forget($jobId);

                throw $e;
            }
        }
    }
}
