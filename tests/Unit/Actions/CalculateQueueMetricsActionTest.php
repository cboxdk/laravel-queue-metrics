<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

beforeEach(function () {
    $this->jobRepository = Mockery::mock(JobMetricsRepository::class);
    $this->queueRepository = Mockery::mock(QueueMetricsRepository::class);
    $this->action = new CalculateQueueMetricsAction($this->jobRepository, $this->queueRepository);

    Carbon::setTestNow('2024-01-15 10:30:00');
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('reads the discovered job list once for all queues', function () {
    $this->queueRepository->shouldReceive('listQueues')->once()->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'emails'],
        ['connection' => 'sqs', 'queue' => 'reports'],
    ]);
    $this->jobRepository->shouldReceive('listJobs')->once()->andReturn([]);
    $this->queueRepository->shouldReceive('recordSnapshot')->times(3);

    expect($this->action->executeForAllQueues())->toBe(3);
});

it('aggregates each queue only from its own job classes when the list is shared', function () {
    $this->queueRepository->shouldReceive('listQueues')->once()->andReturn([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'redis', 'queue' => 'empty'],
    ]);
    $this->jobRepository->shouldReceive('listJobs')->once()->andReturn([
        ['connection' => 'redis', 'queue' => 'default', 'jobClass' => 'App\\Jobs\\SendEmail'],
        ['connection' => 'sqs', 'queue' => 'empty', 'jobClass' => 'App\\Jobs\\Elsewhere'],
    ]);
    $this->jobRepository->shouldReceive('getThroughput')
        ->with('App\\Jobs\\SendEmail', 'redis', 'default', 60)->once()->andReturn(6);
    $this->jobRepository->shouldReceive('getAverageDurationInWindow')
        ->with('App\\Jobs\\SendEmail', 'redis', 'default', 60)->once()->andReturn(50.0);
    $this->jobRepository->shouldReceive('getMetrics')
        ->with('App\\Jobs\\SendEmail', 'redis', 'default')->once()->andReturn([
            'total_processed' => 10,
            'total_failed' => 0,
            'last_processed_at' => Carbon::now(),
        ]);

    $snapshots = [];
    $this->queueRepository->shouldReceive('recordSnapshot')->twice()
        ->andReturnUsing(function (string $connection, string $queue, array $metrics) use (&$snapshots) {
            $snapshots["{$connection}:{$queue}"] = $metrics;
        });

    $this->action->executeForAllQueues();

    expect($snapshots['redis:default']['throughput_per_minute'])->toEqualWithDelta(6.0, 0.001)
        ->and($snapshots['redis:empty']['throughput_per_minute'])->toBe(0.0);
});

it('still reads the job list for a single queue', function () {
    $this->jobRepository->shouldReceive('listJobs')->once()->andReturn([]);
    $this->queueRepository->shouldReceive('recordSnapshot')->once()
        ->with('redis', 'default', Mockery::on(fn (array $metrics) => $metrics['throughput_per_minute'] === 0.0));

    $this->action->execute('redis', 'default');
});
