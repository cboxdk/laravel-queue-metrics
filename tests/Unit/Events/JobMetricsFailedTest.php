<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsFailed;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([JobMetricsFailed::class]);
});

it('can be dispatched with job failure metrics', function () {
    JobMetricsFailed::dispatch(
        'job-123',
        'App\Jobs\ProcessOrder',
        'redis',
        'default',
        150.5,
        25.3,
        12.4,
        'Division by zero in App/Jobs/ProcessOrder.php:42',
        'worker-01',
    );

    Event::assertDispatched(JobMetricsFailed::class, function ($event) {
        return $event->jobId === 'job-123'
            && $event->jobClass === 'App\Jobs\ProcessOrder'
            && $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->durationMs === 150.5
            && $event->memoryMb === 25.3
            && $event->cpuTimeMs === 12.4
            && $event->exceptionMessage === 'Division by zero in App/Jobs/ProcessOrder.php:42'
            && $event->hostname === 'worker-01';
    });
})->group('functional');

it('contains all per-job failure metrics data', function () {
    $event = new JobMetricsFailed(
        jobId: 'job-456',
        jobClass: 'App\Jobs\SendEmail',
        connection: 'sqs',
        queue: 'emails',
        durationMs: 200.0,
        memoryMb: 32.5,
        cpuTimeMs: 18.7,
        exceptionMessage: 'Connection refused in App/Services/Mailer.php:15',
        hostname: 'worker-02',
    );

    expect($event->jobId)->toBe('job-456')
        ->and($event->jobClass)->toBe('App\Jobs\SendEmail')
        ->and($event->connection)->toBe('sqs')
        ->and($event->queue)->toBe('emails')
        ->and($event->durationMs)->toBe(200.0)
        ->and($event->memoryMb)->toBe(32.5)
        ->and($event->cpuTimeMs)->toBe(18.7)
        ->and($event->exceptionMessage)->toBe('Connection refused in App/Services/Mailer.php:15')
        ->and($event->hostname)->toBe('worker-02');
})->group('functional');

it('allows null hostname', function () {
    $event = new JobMetricsFailed(
        jobId: 'job-789',
        jobClass: 'App\Jobs\TestJob',
        connection: 'redis',
        queue: 'default',
        durationMs: 100.0,
        memoryMb: 10.0,
        cpuTimeMs: 5.0,
        exceptionMessage: 'Test exception',
    );

    expect($event->hostname)->toBeNull();
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(JobMetricsFailed::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
