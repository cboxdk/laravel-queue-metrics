<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\HookManager;

/**
 * Record when a job starts processing.
 */
final readonly class RecordJobStartAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private QueueMetricsRepository $queueMetricsRepository,
        private HookManager $hookManager,
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Mark queue as discovered for listQueues() to find it
        $this->queueMetricsRepository->markQueueDiscovered($connection, $queue);

        // Prepare data for hooks
        $data = [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'started_at' => Carbon::now(),
        ];

        // Execute before_record hooks
        $data = $this->hookManager->execute('before_record', $data);
        /** @var array<string, mixed> $data */
        $this->repository->recordStart(
            jobId: $data['job_id'],
            jobClass: $data['job_class'],
            connection: $data['connection'],
            queue: $data['queue'],
            startedAt: $data['started_at'],
        );

        // Execute after_record hooks
        $this->hookManager->execute('after_record', $data);
    }
}
