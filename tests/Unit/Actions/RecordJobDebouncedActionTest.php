<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Actions\RecordJobDebouncedAction;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

beforeEach(function () {
    $this->repository = Mockery::mock(JobMetricsRepository::class);
    $this->action = new RecordJobDebouncedAction($this->repository);

    Carbon::setTestNow('2024-01-15 10:30:00');
    config(['queue-metrics.enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('records debounced job with all parameters', function () {
    $this->repository->shouldReceive('recordDebounced')
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

    $this->repository->shouldNotReceive('recordDebounced');

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
    );
})->group('functional');
