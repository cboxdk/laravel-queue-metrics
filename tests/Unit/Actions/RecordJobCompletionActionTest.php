<?php

declare(strict_types=1);

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobCompletionAction;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

beforeEach(function () {
    $this->repository = Mockery::mock(JobMetricsRepository::class);
    $this->action = new RecordJobCompletionAction($this->repository);

    Carbon::setTestNow('2024-01-15 10:30:00');
    config(['queue-metrics.enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('records job completion with all parameters', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-123',
            'App\Jobs\ProcessOrder',
            'redis',
            'default',
            1500.5,
            25.3,
            300.0,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        durationMs: 1500.5,
        memoryMb: 25.3,
        cpuTimeMs: 300.0,
    );
})->group('functional');

it('records job completion with zero CPU time by default', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-456',
            'App\Jobs\SendEmail',
            'redis',
            'emails',
            500.0,
            10.5,
            0.0,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-456',
        jobClass: 'App\Jobs\SendEmail',
        connection: 'redis',
        queue: 'emails',
        durationMs: 500.0,
        memoryMb: 10.5,
    );
})->group('functional');

it('does nothing when metrics are disabled', function () {
    config(['queue-metrics.enabled' => false]);

    $this->repository->shouldNotReceive('recordCompletion');

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        durationMs: 1000.0,
        memoryMb: 20.0,
    );
})->group('functional');

it('handles very short duration jobs', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-fast',
            'App\Jobs\QuickTask',
            'redis',
            'default',
            0.5,
            5.2,
            0.1,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-fast',
        jobClass: 'App\Jobs\QuickTask',
        connection: 'redis',
        queue: 'default',
        durationMs: 0.5,
        memoryMb: 5.2,
        cpuTimeMs: 0.1,
    );
})->group('functional');

it('handles very long duration jobs', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-slow',
            'App\Jobs\HeavyProcessing',
            'redis',
            'heavy',
            3600000.0,
            512.0,
            3000000.0,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-slow',
        jobClass: 'App\Jobs\HeavyProcessing',
        connection: 'redis',
        queue: 'heavy',
        durationMs: 3600000.0,
        memoryMb: 512.0,
        cpuTimeMs: 3000000.0,
    );
})->group('functional');

it('records completion time at execution moment', function () {
    Carbon::setTestNow('2024-01-15 14:45:30');

    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-789',
            'App\Jobs\GenerateReport',
            'redis',
            'reports',
            2500.0,
            30.0,
            500.0,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-789',
        jobClass: 'App\Jobs\GenerateReport',
        connection: 'redis',
        queue: 'reports',
        durationMs: 2500.0,
        memoryMb: 30.0,
        cpuTimeMs: 500.0,
    );
})->group('functional');

it('handles different queue connections', function () {
    $this->repository->shouldReceive('recordCompletion')
        ->once()
        ->with(
            'job-db',
            'App\Jobs\DatabaseTask',
            'database',
            'sync',
            1200.0,
            15.5,
            250.0,
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-db',
        jobClass: 'App\Jobs\DatabaseTask',
        connection: 'database',
        queue: 'sync',
        durationMs: 1200.0,
        memoryMb: 15.5,
        cpuTimeMs: 250.0,
    );
})->group('functional');
