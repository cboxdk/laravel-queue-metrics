<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\ThroughputStats;

describe('ThroughputStats', function () {
    it('can be created with all properties', function () {
        $stats = new ThroughputStats(
            perMinute: 630.0,
            perHour: 37800.0,
            perDay: 907200.0,
        );

        expect($stats->perMinute)->toBe(630.0)
            ->and($stats->perHour)->toBe(37800.0)
            ->and($stats->perDay)->toBe(907200.0);
    });

    it('can be converted to array', function () {
        $stats = new ThroughputStats(
            perMinute: 630.0,
            perHour: 37800.0,
            perDay: 907200.0,
        );

        expect($stats->toArray())->toBe([
            'per_minute' => 630.0,
            'per_hour' => 37800.0,
            'per_day' => 907200.0,
        ]);
    });

    it('can be created from array', function () {
        $data = [
            'per_minute' => 630.0,
            'per_hour' => 37800.0,
            'per_day' => 907200.0,
        ];

        $stats = ThroughputStats::fromArray($data);

        expect($stats)->toBeInstanceOf(ThroughputStats::class)
            ->and($stats->perMinute)->toBe(630.0)
            ->and($stats->perHour)->toBe(37800.0);
    });

    it('is readonly and immutable', function () {
        $stats = new ThroughputStats(
            perMinute: 630.0,
            perHour: 37800.0,
            perDay: 907200.0,
        );

        expect(fn () => $stats->perMinute = 1000.0)
            ->toThrow(Error::class);
    });
});
