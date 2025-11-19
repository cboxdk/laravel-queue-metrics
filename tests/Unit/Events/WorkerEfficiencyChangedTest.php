<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPeek\LaravelQueueMetrics\Events\WorkerEfficiencyChanged;

beforeEach(function () {
    Event::fake([WorkerEfficiencyChanged::class]);
});

it('can be dispatched with efficiency metrics', function () {
    WorkerEfficiencyChanged::dispatch(
        85.5,
        70.0,
        15.5,
        8,
        2
    );

    Event::assertDispatched(WorkerEfficiencyChanged::class, function ($event) {
        return $event->currentEfficiency === 85.5
            && $event->previousEfficiency === 70.0
            && $event->changePercentage === 15.5
            && $event->activeWorkers === 8
            && $event->idleWorkers === 2;
    });
})->group('functional');

it('recommends scale up when efficiency is high and no idle workers', function () {
    $event = new WorkerEfficiencyChanged(
        currentEfficiency: 92.0,
        previousEfficiency: 85.0,
        changePercentage: 7.0,
        activeWorkers: 10,
        idleWorkers: 0
    );

    expect($event->getScalingRecommendation())->toBe('scale_up')
        ->and($event->currentEfficiency)->toBe(92.0)
        ->and($event->idleWorkers)->toBe(0);
})->group('functional');

it('recommends scale down when efficiency is low with many idle workers', function () {
    $event = new WorkerEfficiencyChanged(
        currentEfficiency: 45.0,
        previousEfficiency: 60.0,
        changePercentage: 15.0,
        activeWorkers: 5,
        idleWorkers: 5
    );

    expect($event->getScalingRecommendation())->toBe('scale_down')
        ->and($event->currentEfficiency)->toBe(45.0)
        ->and($event->idleWorkers)->toBeGreaterThan(3);
})->group('functional');

it('recommends maintain when efficiency is in acceptable range', function () {
    $event = new WorkerEfficiencyChanged(
        currentEfficiency: 75.0,
        previousEfficiency: 70.0,
        changePercentage: 5.0,
        activeWorkers: 8,
        idleWorkers: 2
    );

    expect($event->getScalingRecommendation())->toBe('maintain')
        ->and($event->currentEfficiency)->toBeGreaterThan(50.0)
        ->and($event->currentEfficiency)->toBeLessThan(90.0);
})->group('functional');

it('detects efficiency increase', function () {
    $event = new WorkerEfficiencyChanged(
        currentEfficiency: 80.0,
        previousEfficiency: 65.0,
        changePercentage: 15.0,
        activeWorkers: 10,
        idleWorkers: 0
    );

    expect($event->currentEfficiency)->toBeGreaterThan($event->previousEfficiency)
        ->and($event->changePercentage)->toBe(15.0);
})->group('functional');

it('detects efficiency decrease', function () {
    $event = new WorkerEfficiencyChanged(
        currentEfficiency: 55.0,
        previousEfficiency: 75.0,
        changePercentage: 20.0,
        activeWorkers: 6,
        idleWorkers: 4
    );

    expect($event->currentEfficiency)->toBeLessThan($event->previousEfficiency)
        ->and($event->changePercentage)->toBe(20.0);
})->group('functional');

it('is dispatchable using trait', function () {
    expect(class_uses(WorkerEfficiencyChanged::class))
        ->toContain('Illuminate\Foundation\Events\Dispatchable');
})->group('functional');
