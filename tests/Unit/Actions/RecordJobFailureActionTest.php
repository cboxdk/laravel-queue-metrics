<?php

declare(strict_types=1);

use Carbon\Carbon;
use PHPeek\LaravelQueueMetrics\Actions\RecordJobFailureAction;
use PHPeek\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;

beforeEach(function () {
    $this->repository = Mockery::mock(JobMetricsRepository::class);
    $this->action = new RecordJobFailureAction($this->repository);

    Carbon::setTestNow('2024-01-15 10:30:00');
    config(['queue-metrics.enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
    Mockery::close();
});

it('records job failure with exception details', function () {
    $exception = new RuntimeException('Database connection failed');

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-123',
            'App\Jobs\ProcessOrder',
            'redis',
            'default',
            Mockery::pattern('/Database connection failed in .+:\d+$/'),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        exception: $exception,
    );
})->group('functional');

it('does nothing when metrics are disabled', function () {
    config(['queue-metrics.enabled' => false]);

    $exception = new RuntimeException('Test error');

    $this->repository->shouldNotReceive('recordFailure');

    $this->action->execute(
        jobId: 'job-123',
        jobClass: 'App\Jobs\ProcessOrder',
        connection: 'redis',
        queue: 'default',
        exception: $exception,
    );
})->group('functional');

it('handles different exception types', function () {
    $exception = new InvalidArgumentException('Invalid input data');

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-456',
            'App\Jobs\ValidateData',
            'redis',
            'validation',
            Mockery::pattern('/Invalid input data in .+:\d+$/'),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-456',
        jobClass: 'App\Jobs\ValidateData',
        connection: 'redis',
        queue: 'validation',
        exception: $exception,
    );
})->group('functional');

it('records failure time at execution moment', function () {
    Carbon::setTestNow('2024-01-15 14:45:30');

    $exception = new RuntimeException('Timeout error');

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-789',
            'App\Jobs\SlowTask',
            'redis',
            'default',
            Mockery::pattern('/Timeout error/'),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-789',
        jobClass: 'App\Jobs\SlowTask',
        connection: 'redis',
        queue: 'default',
        exception: $exception,
    );
})->group('functional');

it('handles exceptions with special characters in message', function () {
    $exception = new RuntimeException("Error: 'string' with \"quotes\" & symbols!");

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-special',
            'App\Jobs\SpecialJob',
            'redis',
            'default',
            Mockery::pattern('/Error: \'string\' with "quotes" & symbols!/'),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-special',
        jobClass: 'App\Jobs\SpecialJob',
        connection: 'redis',
        queue: 'default',
        exception: $exception,
    );
})->group('functional');

it('includes file path and line number in exception message', function () {
    $exception = new RuntimeException('Critical error');

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-error',
            'App\Jobs\ErrorJob',
            'redis',
            'default',
            Mockery::on(function ($arg) {
                return str_contains($arg, 'Critical error in')
                    && str_contains($arg, '.php:')
                    && preg_match('/:\d+$/', $arg);
            }),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-error',
        jobClass: 'App\Jobs\ErrorJob',
        connection: 'redis',
        queue: 'default',
        exception: $exception,
    );
})->group('functional');

it('handles different queue connections', function () {
    $exception = new RuntimeException('Database error');

    $this->repository->shouldReceive('recordFailure')
        ->once()
        ->with(
            'job-db',
            'App\Jobs\DatabaseJob',
            'database',
            'sync',
            Mockery::pattern('/Database error/'),
            Mockery::type(Carbon::class),
            null,
        );

    $this->action->execute(
        jobId: 'job-db',
        jobClass: 'App\Jobs\DatabaseJob',
        connection: 'database',
        queue: 'sync',
        exception: $exception,
    );
})->group('functional');
