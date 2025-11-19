<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use PHPeek\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use PHPeek\LaravelQueueMetrics\Events\BaselineRecalculated;

beforeEach(function () {
    Event::fake([BaselineRecalculated::class]);
    Carbon::setTestNow('2024-01-15 10:30:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('can be dispatched with baseline data', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\Jobs\ProcessOrder',
        cpuPercentPerJob: 5.5,
        memoryMbPerJob: 25.3,
        avgDurationMs: 150.5,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: Carbon::now(),
    );

    BaselineRecalculated::dispatch(
        'redis',
        'default',
        $baseline,
        true
    );

    Event::assertDispatched(BaselineRecalculated::class, function ($event) {
        return $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->baseline->jobClass === 'App\Jobs\ProcessOrder'
            && $event->significantChange === true;
    });
})->group('functional');

it('indicates significant change when baseline changes by more than 20%', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\Jobs\TestJob',
        cpuPercentPerJob: 10.0,
        memoryMbPerJob: 50.0,
        avgDurationMs: 200.0,
        sampleCount: 150,
        confidenceScore: 0.90,
        calculatedAt: Carbon::now(),
    );

    $event = new BaselineRecalculated('redis', 'default', $baseline, true);

    expect($event->significantChange)->toBeTrue()
        ->and($event->baseline->cpuPercentPerJob)->toBe(10.0)
        ->and($event->baseline->confidenceScore)->toBe(0.90);
})->group('functional');

it('indicates no significant change when baseline is stable', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\Jobs\StableJob',
        cpuPercentPerJob: 5.0,
        memoryMbPerJob: 25.0,
        avgDurationMs: 100.0,
        sampleCount: 200,
        confidenceScore: 1.0,
        calculatedAt: Carbon::now(),
    );

    $event = new BaselineRecalculated('redis', 'default', $baseline, false);

    expect($event->significantChange)->toBeFalse()
        ->and($event->baseline->confidenceScore)->toBe(1.0);
})->group('functional');

it('handles aggregated baseline without job class', function () {
    $aggregatedBaseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '', // Empty = aggregated
        cpuPercentPerJob: 7.5,
        memoryMbPerJob: 35.0,
        avgDurationMs: 175.0,
        sampleCount: 500,
        confidenceScore: 0.95,
        calculatedAt: Carbon::now(),
    );

    $event = new BaselineRecalculated('redis', 'default', $aggregatedBaseline, true);

    expect($event->baseline->jobClass)->toBe('')
        ->and($event->baseline->sampleCount)->toBe(500);
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(BaselineRecalculated::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
