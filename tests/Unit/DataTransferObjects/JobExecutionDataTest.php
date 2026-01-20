<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\JobExecutionData;

describe('JobExecutionData', function () {
    it('can be created with all properties', function () {
        $data = new JobExecutionData(
            totalProcessed: 1000,
            totalFailed: 50,
            successRate: 95.24,
            failureRate: 4.76,
        );

        expect($data->totalProcessed)->toBe(1000)
            ->and($data->totalFailed)->toBe(50)
            ->and($data->successRate)->toBe(95.24)
            ->and($data->failureRate)->toBe(4.76);
    });

    it('can be converted to array', function () {
        $data = new JobExecutionData(
            totalProcessed: 1000,
            totalFailed: 50,
            successRate: 95.24,
            failureRate: 4.76,
        );

        $array = $data->toArray();

        expect($array)->toHaveKey('total_processed', 1000)
            ->toHaveKey('total_failed', 50)
            ->toHaveKey('success_rate', 95.24)
            ->toHaveKey('failure_rate', 4.76);
    });

    it('can be created from array', function () {
        $arrayData = [
            'total_processed' => 1000,
            'total_failed' => 50,
        ];

        $data = JobExecutionData::fromArray($arrayData);

        expect($data)->toBeInstanceOf(JobExecutionData::class)
            ->and($data->totalProcessed)->toBe(1000)
            ->and($data->totalFailed)->toBe(50);
    });

    it('calculates success and failure rates correctly', function () {
        $data = JobExecutionData::fromArray([
            'total_processed' => 95,
            'total_failed' => 5,
        ]);

        expect($data->successRate)->toBe(95.0)
            ->and($data->failureRate)->toBe(5.0);
    });

    it('handles zero totals gracefully', function () {
        $data = JobExecutionData::fromArray([
            'total_processed' => 0,
            'total_failed' => 0,
        ]);

        expect($data->successRate)->toBe(0.0)
            ->and($data->failureRate)->toBe(0.0);
    });

    it('is readonly and immutable', function () {
        $data = new JobExecutionData(
            totalProcessed: 1000,
            totalFailed: 50,
            successRate: 95.24,
            failureRate: 4.76,
        );

        expect(fn () => $data->totalProcessed = 2000)
            ->toThrow(Error::class);
    });
});
