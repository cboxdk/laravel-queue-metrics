<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Repositories\Contracts\BaselineRepository;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\WorkerMetricsQueryService;
use Cbox\LaravelQueueMetrics\Telemetry\QueueMetricsTelemetrySnapshot;

function bindCountingSnapshotServices(): object
{
    $queueService = new class
    {
        public int $calls = 0;

        /**
         * @return array<string, array<string, mixed>>
         */
        public function getAllQueuesWithMetrics(): array
        {
            $this->calls++;

            return [];
        }
    };

    $workerService = new class
    {
        /**
         * @return array<string, mixed>
         */
        public function getWorkersSummary(): array
        {
            return [];
        }
    };

    app()->instance(QueueMetricsQueryService::class, $queueService);
    app()->instance(WorkerMetricsQueryService::class, $workerService);
    app()->instance(BaselineRepository::class, Mockery::mock(BaselineRepository::class));

    return $queueService;
}

it('builds the snapshot only once per scrape even with the shared cache disabled', function () {
    config(['queue-metrics.telemetry.cache_ttl' => 0]);

    $queueService = bindCountingSnapshotServices();

    $snapshot = new QueueMetricsTelemetrySnapshot(app());

    $snapshot->snapshot();
    $snapshot->snapshot();
    $snapshot->snapshot();

    expect($queueService->calls)->toBe(1);
})->group('functional');
