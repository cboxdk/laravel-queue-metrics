<?php

declare(strict_types=1);

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobStartAction;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;

beforeEach(function () {
    $this->repository = Mockery::mock(JobMetricsRepository::class);
    $this->queueRepository = Mockery::mock(QueueMetricsRepository::class);
    $this->action = new RecordJobStartAction($this->repository, $this->queueRepository);

    Carbon::setTestNow('2024-01-15 10:30:00');
    config(['queue-metrics.enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('records job start with all parameters', function () {
    $this->queueRepository->shouldReceive('markQueueDiscovered')
        ->once()
        ->with('redis', 'default');

    $this->repository->shouldReceive('recordStart')
        ->once()
        ->with(
            'job-123',
            'App\Jobs\ProcessOrder',
            'redis',
            'default',
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
    );
})->group('functional');

it('does nothing when metrics are disabled', function () {
    config(['queue-metrics.enabled' => false]);

    $this->repository->shouldNotReceive('recordStart');

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
    );
})->group('functional');

it('handles different queue connections', function () {
    $this->queueRepository->shouldReceive('markQueueDiscovered')
        ->once()
        ->with('database', 'emails');

    $this->repository->shouldReceive('recordStart')
        ->once()
        ->with(
            'job-456',
            'App\Jobs\SendEmail',
            'database',
            'emails',
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        jobId: 'job-456',
        jobClass: 'App\Jobs\SendEmail',
        connection: 'database',
        queue: 'emails',
    );
})->group('functional');

it('records start time at execution moment', function () {
    Carbon::setTestNow('2024-01-15 14:45:30');

    $this->queueRepository->shouldReceive('markQueueDiscovered')
        ->once()
        ->with('redis', 'reports');

    $this->repository->shouldReceive('recordStart')
        ->once()
        ->with(
            'job-789',
            'App\Jobs\GenerateReport',
            'redis',
            'reports',
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        jobId: 'job-789',
        jobClass: 'App\Jobs\GenerateReport',
        connection: 'redis',
        queue: 'reports',
    );
})->group('functional');

it('handles job IDs with special characters', function () {
    $this->queueRepository->shouldReceive('markQueueDiscovered')
        ->once()
        ->with('redis', 'default');

    $this->repository->shouldReceive('recordStart')
        ->once()
        ->with(
            'job-abc-123-xyz',
            'App\Jobs\ProcessData',
            'redis',
            'default',
            Mockery::type(Carbon::class),
        );

    $this->action->execute(
        jobId: 'job-abc-123-xyz',
        jobClass: 'App\Jobs\ProcessData',
        connection: 'redis',
        queue: 'default',
    );
})->group('functional');
