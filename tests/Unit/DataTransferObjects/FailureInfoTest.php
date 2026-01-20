<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\FailureInfo;

describe('FailureInfo', function () {
    it('can be created with all properties', function () {
        $failedAt = now();
        $info = new FailureInfo(
            count: 5,
            rate: 0.05,
            lastFailedAt: $failedAt,
            lastException: 'RuntimeException',
        );

        expect($info->count)->toBe(5)
            ->and($info->rate)->toBe(0.05)
            ->and($info->lastException)->toBe('RuntimeException')
            ->and($info->lastFailedAt)->toBeInstanceOf(Carbon\Carbon::class)
            ->and($info->lastFailedAt->toIso8601String())->toBe($failedAt->toIso8601String());
    });

    it('can have null last exception and failed at', function () {
        $info = new FailureInfo(
            count: 0,
            rate: 0.0,
            lastFailedAt: null,
            lastException: null,
        );

        expect($info->count)->toBe(0)
            ->and($info->rate)->toBe(0.0)
            ->and($info->lastException)->toBeNull()
            ->and($info->lastFailedAt)->toBeNull();
    });

    it('can be converted to array', function () {
        $failedAt = now();
        $info = new FailureInfo(
            count: 5,
            rate: 0.05,
            lastFailedAt: $failedAt,
            lastException: 'RuntimeException',
        );

        $array = $info->toArray();

        expect($array)->toHaveKey('count', 5)
            ->toHaveKey('rate', 0.05)
            ->toHaveKey('last_exception', 'RuntimeException')
            ->toHaveKey('last_failed_at')
            ->and($array['last_failed_at'])->toBe($failedAt->toIso8601String());
    });

    it('can be created from array', function () {
        $data = [
            'count' => 5,
            'rate' => 0.05,
            'last_exception' => 'RuntimeException',
            'last_failed_at' => now()->toIso8601String(),
        ];

        $info = FailureInfo::fromArray($data);

        expect($info)->toBeInstanceOf(FailureInfo::class)
            ->and($info->count)->toBe(5)
            ->and($info->rate)->toBe(0.05);
    });

    it('is readonly and immutable', function () {
        $info = new FailureInfo(
            count: 5,
            rate: 0.05,
            lastFailedAt: now(),
            lastException: 'RuntimeException',
        );

        expect(fn () => $info->count = 10)
            ->toThrow(Error::class);
    });
});
