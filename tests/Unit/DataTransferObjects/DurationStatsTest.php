<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\DurationStats;

describe('DurationStats', function () {
    it('can be created with all properties', function () {
        $stats = new DurationStats(
            avg: 100.5,
            min: 50.0,
            max: 200.0,
            p50: 95.0,
            p95: 180.0,
            p99: 195.0,
            stddev: 25.5,
        );

        expect($stats->avg)->toBe(100.5)
            ->and($stats->min)->toBe(50.0)
            ->and($stats->max)->toBe(200.0)
            ->and($stats->p50)->toBe(95.0)
            ->and($stats->p95)->toBe(180.0)
            ->and($stats->p99)->toBe(195.0)
            ->and($stats->stddev)->toBe(25.5);
    });

    it('can be converted to array', function () {
        $stats = new DurationStats(
            avg: 100.5,
            min: 50.0,
            max: 200.0,
            p50: 95.0,
            p95: 180.0,
            p99: 195.0,
            stddev: 25.5,
        );

        expect($stats->toArray())->toBe([
            'avg' => 100.5,
            'min' => 50.0,
            'max' => 200.0,
            'p50' => 95.0,
            'p95' => 180.0,
            'p99' => 195.0,
            'stddev' => 25.5,
        ]);
    });

    it('can be created from array', function () {
        $data = [
            'avg' => 100.5,
            'min' => 50.0,
            'max' => 200.0,
            'p50' => 95.0,
            'p95' => 180.0,
            'p99' => 195.0,
            'stddev' => 25.5,
        ];

        $stats = DurationStats::fromArray($data);

        expect($stats)->toBeInstanceOf(DurationStats::class)
            ->and($stats->avg)->toBe(100.5)
            ->and($stats->min)->toBe(50.0);
    });

    it('is readonly and immutable', function () {
        $stats = new DurationStats(
            avg: 100.5,
            min: 50.0,
            max: 200.0,
            p50: 95.0,
            p95: 180.0,
            p99: 195.0,
            stddev: 25.5,
        );

        expect(fn () => $stats->avg = 200.0)
            ->toThrow(Error::class);
    });
});
