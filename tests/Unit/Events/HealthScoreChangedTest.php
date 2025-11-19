<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPeek\LaravelQueueMetrics\Events\HealthScoreChanged;

beforeEach(function () {
    Event::fake([HealthScoreChanged::class]);
});

it('can be dispatched with health score data', function () {
    HealthScoreChanged::dispatch(
        'redis',
        'default',
        45.0,
        70.0,
        'critical'
    );

    Event::assertDispatched(HealthScoreChanged::class, function ($event) {
        return $event->connection === 'redis'
            && $event->queue === 'default'
            && $event->currentScore === 45.0
            && $event->previousScore === 70.0
            && $event->status === 'critical';
    });
})->group('functional');

it('detects critical severity when score drops significantly', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 40.0,
        previousScore: 75.0,
        status: 'critical'
    );

    expect($event->getSeverity())->toBe('critical')
        ->and($event->status)->toBe('critical')
        ->and($event->currentScore)->toBeLessThan(50.0);
})->group('functional');

it('detects warning severity when score changes moderately', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 60.0,
        previousScore: 85.0,
        status: 'warning'
    );

    expect($event->getSeverity())->toBe('warning')
        ->and($event->status)->toBe('warning')
        ->and(abs($event->currentScore - $event->previousScore))->toBeGreaterThan(20.0);
})->group('functional');

it('detects info severity when score changes slightly', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 75.0,
        previousScore: 85.0,
        status: 'healthy'
    );

    expect($event->getSeverity())->toBe('info')
        ->and(abs($event->currentScore - $event->previousScore))->toBeLessThan(15.0);
})->group('functional');

it('detects normal severity when score is stable', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 82.0,
        previousScore: 85.0,
        status: 'healthy'
    );

    expect($event->getSeverity())->toBe('normal')
        ->and(abs($event->currentScore - $event->previousScore))->toBeLessThan(10.0);
})->group('functional');

it('handles healthy status transition', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 85.0,
        previousScore: 50.0,
        status: 'healthy'
    );

    expect($event->status)->toBe('healthy')
        ->and($event->currentScore)->toBeGreaterThan($event->previousScore)
        ->and($event->getSeverity())->toBe('critical'); // Large change = critical severity
})->group('functional');

it('handles warning status transition', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 65.0,
        previousScore: 80.0,
        status: 'warning'
    );

    expect($event->status)->toBe('warning')
        ->and($event->currentScore)->toBeGreaterThan(50.0)
        ->and($event->currentScore)->toBeLessThan(80.0);
})->group('functional');

it('handles critical status transition', function () {
    $event = new HealthScoreChanged(
        connection: 'redis',
        queue: 'default',
        currentScore: 35.0,
        previousScore: 70.0,
        status: 'critical'
    );

    expect($event->status)->toBe('critical')
        ->and($event->currentScore)->toBeLessThan(50.0)
        ->and($event->getSeverity())->toBe('critical');
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(HealthScoreChanged::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
