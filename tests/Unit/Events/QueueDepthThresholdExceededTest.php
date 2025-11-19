<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use PHPeek\LaravelQueueMetrics\Events\QueueDepthThresholdExceeded;

beforeEach(function () {
    Event::fake([QueueDepthThresholdExceeded::class]);
    Carbon::setTestNow('2024-01-15 10:30:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('can be dispatched when queue depth exceeds threshold', function () {
    $depthData = new QueueDepthData(
        connection: 'redis',
        queue: 'default',
        pendingJobs: 150,
        reservedJobs: 10,
        delayedJobs: 5,
        oldestPendingJobAge: Carbon::now()->subMinutes(10),
        oldestDelayedJobAge: null,
        measuredAt: Carbon::now(),
    );

    QueueDepthThresholdExceeded::dispatch($depthData, 100, 50.0);

    Event::assertDispatched(QueueDepthThresholdExceeded::class, function ($event) {
        return $event->depth->connection === 'redis'
            && $event->depth->queue === 'default'
            && $event->threshold === 100
            && $event->percentageOver === 50.0;
    });
})->group('functional');

it('calculates percentage over threshold correctly', function () {
    $depthData = new QueueDepthData(
        connection: 'redis',
        queue: 'high',
        pendingJobs: 200,
        reservedJobs: 0,
        delayedJobs: 0,
        oldestPendingJobAge: null,
        oldestDelayedJobAge: null,
        measuredAt: Carbon::now(),
    );

    $event = new QueueDepthThresholdExceeded($depthData, 100, 100.0);

    expect($event->percentageOver)->toBe(100.0) // 100% over threshold
        ->and($event->depth->totalJobs())->toBe(200);
})->group('functional');

it('provides depth data for horizontal scaling decisions', function () {
    $depthData = new QueueDepthData(
        connection: 'redis',
        queue: 'default',
        pendingJobs: 500,
        reservedJobs: 50,
        delayedJobs: 10,
        oldestPendingJobAge: Carbon::now()->subMinutes(30),
        oldestDelayedJobAge: Carbon::now()->subHours(2),
        measuredAt: Carbon::now(),
    );

    $event = new QueueDepthThresholdExceeded($depthData, 100, 460.0);

    expect($event->depth->totalJobs())->toBe(560)
        ->and($event->depth->hasBacklog())->toBeTrue()
        ->and($event->depth->pendingJobs)->toBe(500)
        ->and($event->percentageOver)->toBe(460.0);
})->group('functional');

it('includes oldest job age information', function () {
    $oldestAge = Carbon::now()->subHours(1);

    $depthData = new QueueDepthData(
        connection: 'redis',
        queue: 'default',
        pendingJobs: 150,
        reservedJobs: 0,
        delayedJobs: 0,
        oldestPendingJobAge: $oldestAge,
        oldestDelayedJobAge: null,
        measuredAt: Carbon::now(),
    );

    $event = new QueueDepthThresholdExceeded($depthData, 100, 50.0);

    expect($event->depth->secondsOldestPendingJob())->toBeGreaterThan(3500.0) // ~1 hour
        ->and($event->depth->oldestPendingJobAge)->toBe($oldestAge);
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(QueueDepthThresholdExceeded::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
