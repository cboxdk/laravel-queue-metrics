<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Events\JobMetricsDebounced;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([JobMetricsDebounced::class]);
});

it('can be dispatched with debounced job data', function () {
    JobMetricsDebounced::dispatch(
        'job-123',
        'App\Jobs\ProcessOrder',
        'redis',
        'default',
        'worker-01',
    );

    Event::assertDispatched(JobMetricsDebounced::class, function ($event) {
        return $event->jobId === 'job-123'
            && $event->jobClass === 'App\Jobs\ProcessOrder'
            && $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->hostname === 'worker-01';
    });
})->group('functional');

it('contains all debounced job data', function () {
    $event = new JobMetricsDebounced(
        jobId: 'job-456',
        jobClass: 'App\Jobs\SendEmail',
        connection: 'sqs',
        queue: 'emails',
        hostname: 'worker-02',
    );

    expect($event->jobId)->toBe('job-456')
        ->and($event->jobClass)->toBe('App\Jobs\SendEmail')
        ->and($event->connection)->toBe('sqs')
        ->and($event->queue)->toBe('emails')
        ->and($event->hostname)->toBe('worker-02');
})->group('functional');

it('allows null hostname', function () {
    $event = new JobMetricsDebounced(
        jobId: 'job-789',
        jobClass: 'App\Jobs\TestJob',
        connection: 'redis',
        queue: 'default',
    );

    expect($event->hostname)->toBeNull();
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(JobMetricsDebounced::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
