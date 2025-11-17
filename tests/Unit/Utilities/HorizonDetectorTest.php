<?php

declare(strict_types=1);

use PHPeek\LaravelQueueMetrics\Utilities\HorizonDetector;

describe('HorizonDetector', function () {
    beforeEach(function () {
        // Backup original $_SERVER['argv']
        $this->originalArgv = $_SERVER['argv'] ?? [];
    });

    afterEach(function () {
        // Restore original $_SERVER['argv']
        $_SERVER['argv'] = $this->originalArgv;
    });

    it('detects standard queue worker', function () {
        $_SERVER['argv'] = ['artisan', 'queue:work', 'redis'];

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeFalse();
        expect($context->supervisorName)->toBeNull();
        expect($context->parentId)->toBeNull();
        expect($context->workersName)->toBeNull();
    });

    it('detects Horizon worker with supervisor name', function () {
        $_SERVER['argv'] = [
            'artisan',
            'horizon:work',
            '--supervisor-name=supervisor-1',
            '--parent-id=12345',
            '--workers-name=default-pool',
        ];

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeTrue();
        expect($context->supervisorName)->toBe('supervisor-1');
        expect($context->parentId)->toBe(12345);
        expect($context->workersName)->toBe('default-pool');
    });

    it('detects Horizon worker with equals sign format', function () {
        $_SERVER['argv'] = [
            'artisan',
            'horizon:work',
            '--supervisor-name=my-supervisor',
        ];

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeTrue();
        expect($context->supervisorName)->toBe('my-supervisor');
    });

    it('detects Horizon worker with space-separated format', function () {
        $_SERVER['argv'] = [
            'artisan',
            'horizon:work',
            '--supervisor-name',
            'my-supervisor',
            '--parent-id',
            '99999',
        ];

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeTrue();
        expect($context->supervisorName)->toBe('my-supervisor');
        expect($context->parentId)->toBe(99999);
    });

    it('returns notHorizon when supervisor name is missing', function () {
        $_SERVER['argv'] = [
            'artisan',
            'horizon:work',
            '--parent-id=12345',
        ];

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeFalse();
    });

    it('generates standard worker ID for queue:work', function () {
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        $workerId = HorizonDetector::generateWorkerId();

        expect($workerId)->toStartWith('worker_');
        expect($workerId)->not->toContain('horizon');
    });

    it('generates Horizon worker ID with supervisor name', function () {
        $_SERVER['argv'] = [
            'artisan',
            'horizon:work',
            '--supervisor-name=supervisor-1',
        ];

        $workerId = HorizonDetector::generateWorkerId();

        expect($workerId)->toStartWith('worker_horizon_supervisor-1_');
        expect($workerId)->toContain('supervisor-1');
    });

    it('handles missing argv gracefully', function () {
        unset($_SERVER['argv']);

        $context = HorizonDetector::detect();

        expect($context->isHorizon)->toBeFalse();
    });
});
