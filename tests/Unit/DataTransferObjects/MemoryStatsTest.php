<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\MemoryStats;

describe('MemoryStats', function () {
    it('can be created with all properties', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->avg)->toBe(128.5)
            ->and($stats->peak)->toBe(256.0)
            ->and($stats->p95)->toBe(240.0)
            ->and($stats->p99)->toBe(250.0);
    });

    it('can be converted to array', function () {
        $stats = new MemoryStats(
            avg: 128.5,
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect($stats->toArray())->toBe([
            'avg' => 128.5,
            'peak' => 256.0,
            'p95' => 240.0,
            'p99' => 250.0,
        ]);
    });

    it('can be created from array', function () {
        $data = [
            'avg' => 128.5,
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
            peak: 256.0,
            p95: 240.0,
            p99: 250.0,
        );

        expect(fn () => $stats->avg = 200.0)
            ->toThrow(Error::class);
    });
});
