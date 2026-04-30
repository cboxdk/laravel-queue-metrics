<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\MemoryStats;

describe('MemoryStats', function () {
    it('can be created with all properties', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            avgIncremental: 0.0,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->avg)->toBe(128.5)
            ->and($stats->avgIncremental)->toBe(0.0)
            ->and($stats->peak)->toBe(256.0)
            ->and($stats->p95)->toBe(240.0)
            ->and($stats->p99)->toBe(250.0);
    });

    it('can be converted to array', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            avgIncremental: 0.0,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->toArray())->toBe([
            'avg' => 128.5,
            'avg_incremental' => 0.0,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ]);
    });

    it('can be created from array', function () {
        $data = [
            'avg' => 128.5,
            'avg_incremental' => 0.0,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ];

        $stats = MemoryStats::fromArray($data);

        expect($stats)->toBeInstanceOf(MemoryStats::class)
            ->and($stats->avg)->toBe(128.5)
            ->and($stats->peak)->toBe(256.0);
    });

    it('is readonly and immutable', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            avgIncremental: 0.0,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect(fn () => $stats->avg = 200.0)
            ->toThrow(Error::class);
    });

    it('can be created with avgIncremental property', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            avgIncremental: 12.3,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->avg)->toBe(128.5)
            ->and($stats->avgIncremental)->toBe(12.3)
            ->and($stats->peak)->toBe(256.0)
            ->and($stats->p95)->toBe(240.0)
            ->and($stats->p99)->toBe(250.0);
    });

    it('includes avg_incremental in array output', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            avgIncremental: 12.3,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->toArray())->toBe([
            'avg' => 128.5,
            'avg_incremental' => 12.3,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ]);
    });

    it('can be created from array with avg_incremental', function () {
        $data = [
            'avg' => 128.5,
            'avg_incremental' => 12.3,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ];

        $stats = MemoryStats::fromArray($data);

        expect($stats->avgIncremental)->toBe(12.3);
    });

    it('defaults avg_incremental to zero when missing from array', function () {
        $data = [
            'avg' => 128.5,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ];

        $stats = MemoryStats::fromArray($data);

        expect($stats->avgIncremental)->toBe(0.0);
    });
});
