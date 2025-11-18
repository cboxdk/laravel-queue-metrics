<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Actions;

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Support\HookManager;
use Throwable;

/**
 * Record when a job fails.
 */
final readonly class RecordJobFailureAction
{
    public function __construct(
        private JobMetricsRepository $repository,
        private HookManager $hookManager,
    ) {}

    public function execute(
        string $jobId,
        string $jobClass,
        string $connection,
        string $queue,
        Throwable $exception,
        ?string $hostname = null,
    ): void {
        if (! config('queue-metrics.enabled', true)) {
            return;
        }

        // Prepare data for hooks
        $exceptionMessage = $exception->getMessage().' in '.$exception->getFile().':'.$exception->getLine();
        $data = [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            'connection' => $connection,
            'queue' => $queue,
            'exception' => $exceptionMessage,
            'hostname' => $hostname,
            'failed_at' => Carbon::now(),
        ];

        // Execute before_record hooks
        $data = $this->hookManager->execute('before_record', $data);
        /** @var array<string, mixed> $data */
        $this->repository->recordFailure(
            jobId: $data['job_id'],
            jobClass: $data['job_class'],
            connection: $data['connection'],
            queue: $data['queue'],
            exception: $data['exception'],
            failedAt: $data['failed_at'],
            hostname: $data['hostname'],
        );

        // Execute after_record hooks
        $this->hookManager->execute('after_record', $data);
    }
}
