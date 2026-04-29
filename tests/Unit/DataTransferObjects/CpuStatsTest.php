<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\CpuStats;

describe('CpuStats', function () {
    it('can be created with all properties', function () {
        $stats = new CpuStats(
            avg: 12.5,
            peak: 45.0,
            p95: 30.0,
            p99: 40.0,
        );

        expect($stats->avg)->toBe(12.5)
            ->and($stats->peak)->toBe(45.0)
            ->and($stats->p95)->toBe(30.0)
            ->and($stats->p99)->toBe(40.0);
    });

    it('can be converted to array', function () {
        $stats = new CpuStats(
            avg: 12.5,
            peak: 45.0,
            p95: 30.0,
            p99: 40.0,
        );

        expect($stats->toArray())->toBe([
            'avg' => 12.5,
            'peak' => 45.0,
            'p95' => 30.0,
            'p99' => 40.0,
        ]);
    });

    it('can be created from array', function () {
        $data = [
            'avg' => 12.5,
            'peak' => 45.0,
            'p95' => 30.0,
            'p99' => 40.0,
        ];

        $stats = CpuStats::fromArray($data);

        expect($stats)->toBeInstanceOf(CpuStats::class)
            ->and($stats->avg)->toBe(12.5)
            ->and($stats->peak)->toBe(45.0)
            ->and($stats->p95)->toBe(30.0)
            ->and($stats->p99)->toBe(40.0);
    });

    it('defaults missing keys to zero in fromArray', function () {
        $stats = CpuStats::fromArray([]);

        expect($stats->avg)->toBe(0.0)
            ->and($stats->peak)->toBe(0.0)
            ->and($stats->p95)->toBe(0.0)
            ->and($stats->p99)->toBe(0.0);
    });

    it('handles non-numeric values in fromArray', function () {
        $stats = CpuStats::fromArray([
            'avg' => 'not-a-number',
            'peak' => null,
            'p95' => [],
            'p99' => true,
        ]);

        expect($stats->avg)->toBe(0.0)
            ->and($stats->peak)->toBe(0.0)
            ->and($stats->p95)->toBe(0.0)
            ->and($stats->p99)->toBe(0.0);
    });

    it('casts numeric strings in fromArray', function () {
        $stats = CpuStats::fromArray([
            'avg' => '12.5',
            'peak' => '45',
            'p95' => '30.0',
            'p99' => '40.0',
        ]);

        expect($stats->avg)->toBe(12.5)
            ->and($stats->peak)->toBe(45.0)
            ->and($stats->p95)->toBe(30.0)
            ->and($stats->p99)->toBe(40.0);
    });

    it('preserves zero values', function () {
        $stats = new CpuStats(
            avg: 0.0,
            peak: 0.0,
            p95: 0.0,
            p99: 0.0,
        );

        expect($stats->toArray())->toBe([
            'avg' => 0.0,
            'peak' => 0.0,
            'p95' => 0.0,
            'p99' => 0.0,
        ]);
    });

    it('roundtrips through toArray and fromArray', function () {
        $original = new CpuStats(
            avg: 15.75,
            peak: 98.2,
            p95: 85.0,
            p99: 95.5,
        );

        $restored = CpuStats::fromArray($original->toArray());

        expect($restored->avg)->toBe($original->avg)
            ->and($restored->peak)->toBe($original->peak)
            ->and($restored->p95)->toBe($original->p95)
            ->and($restored->p99)->toBe($original->p99);
    });

    it('is readonly and immutable', function () {
        $stats = new CpuStats(
            avg: 12.5,
            peak: 45.0,
            p95: 30.0,
            p99: 40.0,
        );

        expect(fn () => $stats->avg = 200.0)
            ->toThrow(Error::class);
    });
});
