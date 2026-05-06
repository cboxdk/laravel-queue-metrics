<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\DataTransferObjects\BaselineData;
use Cbox\LaravelQueueMetrics\Repositories\DatabaseBaselineRepository;
use Cbox\LaravelQueueMetrics\Support\DatabaseMetricsStore;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $createTables = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $createTables->up();

    $addSetExpiry = include __DIR__.'/../../../database/migrations/2024_01_01_000002_add_expires_at_to_queue_metrics_sets.php';
    $addSetExpiry->up();

    $this->store = new DatabaseMetricsStore;
    $this->repo = new DatabaseBaselineRepository($this->store);
});

// --- storeBaseline and getBaseline round-trip ---

test('storeBaseline and getBaseline round-trip for aggregate baseline', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: Carbon::parse('2025-06-01 12:00:00'),
    );

    $this->repo->storeBaseline($baseline);

    $retrieved = $this->repo->getBaseline('redis', 'default');

    expect($retrieved)->not->toBeNull();
    expect($retrieved->connection)->toBe('redis');
    expect($retrieved->queue)->toBe('default');
    expect($retrieved->jobClass)->toBe('');
    expect($retrieved->cpuPercentPerJob)->toBe(2.5);
    expect($retrieved->memoryMbPerJob)->toBe(64.0);
    expect($retrieved->avgDurationMs)->toBe(150.0);
    expect($retrieved->sampleCount)->toBe(100);
    expect($retrieved->confidenceScore)->toBe(0.85);
    expect($retrieved->calculatedAt->toDateTimeString())->toBe('2025-06-01 12:00:00');
});

test('getBaseline returns null when no baseline exists', function () {
    expect($this->repo->getBaseline('redis', 'default'))->toBeNull();
});

// --- storeBaseline with jobClass and getJobClassBaseline ---

test('storeBaseline with jobClass and getJobClassBaseline round-trip', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\\Jobs\\SendEmail',
        cpuPercentPerJob: 3.0,
        memoryMbPerJob: 128.0,
        avgDurationMs: 200.0,
        sampleCount: 50,
        confidenceScore: 0.75,
        calculatedAt: Carbon::parse('2025-06-01 12:00:00'),
    );

    $this->repo->storeBaseline($baseline);

    $retrieved = $this->repo->getJobClassBaseline('redis', 'default', 'App\\Jobs\\SendEmail');

    expect($retrieved)->not->toBeNull();
    expect($retrieved->connection)->toBe('redis');
    expect($retrieved->queue)->toBe('default');
    expect($retrieved->jobClass)->toBe('App\\Jobs\\SendEmail');
    expect($retrieved->cpuPercentPerJob)->toBe(3.0);
    expect($retrieved->memoryMbPerJob)->toBe(128.0);
    expect($retrieved->avgDurationMs)->toBe(200.0);
    expect($retrieved->sampleCount)->toBe(50);
    expect($retrieved->confidenceScore)->toBe(0.75);
});

test('getJobClassBaseline returns null when no baseline exists', function () {
    expect($this->repo->getJobClassBaseline('redis', 'default', 'App\\Jobs\\Nope'))->toBeNull();
});

// --- getBaselines with multiple queue pairs ---

test('getBaselines returns baselines for multiple queue pairs', function () {
    $baseline1 = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    $baseline2 = new BaselineData(
        connection: 'sqs',
        queue: 'emails',
        jobClass: '',
        cpuPercentPerJob: 5.0,
        memoryMbPerJob: 256.0,
        avgDurationMs: 500.0,
        sampleCount: 200,
        confidenceScore: 0.9,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($baseline1);
    $this->repo->storeBaseline($baseline2);

    $result = $this->repo->getBaselines([
        ['connection' => 'redis', 'queue' => 'default'],
        ['connection' => 'sqs', 'queue' => 'emails'],
        ['connection' => 'redis', 'queue' => 'nonexistent'],
    ]);

    expect($result)->toHaveCount(2);
    expect($result)->toHaveKey('redis:default');
    expect($result)->toHaveKey('sqs:emails');
    expect($result['redis:default']->cpuPercentPerJob)->toBe(2.5);
    expect($result['sqs:emails']->cpuPercentPerJob)->toBe(5.0);
});

test('getBaselines returns empty array for empty input', function () {
    expect($this->repo->getBaselines([]))->toBe([]);
});

// --- getJobClassBaselines ---

test('getJobClassBaselines returns per-class baselines excluding aggregate', function () {
    // Store aggregate baseline
    $aggregate = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    // Store per-class baselines
    $class1 = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\\Jobs\\SendEmail',
        cpuPercentPerJob: 3.0,
        memoryMbPerJob: 128.0,
        avgDurationMs: 200.0,
        sampleCount: 50,
        confidenceScore: 0.75,
        calculatedAt: now(),
    );

    $class2 = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\\Jobs\\ProcessOrder',
        cpuPercentPerJob: 5.0,
        memoryMbPerJob: 256.0,
        avgDurationMs: 500.0,
        sampleCount: 75,
        confidenceScore: 0.8,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($aggregate);
    $this->repo->storeBaseline($class1);
    $this->repo->storeBaseline($class2);

    $baselines = $this->repo->getJobClassBaselines('redis', 'default');

    expect($baselines)->toHaveCount(2);

    $jobClasses = array_map(fn (BaselineData $b) => $b->jobClass, $baselines);
    expect($jobClasses)->toContain('App\\Jobs\\SendEmail');
    expect($jobClasses)->toContain('App\\Jobs\\ProcessOrder');
    expect($jobClasses)->not->toContain('');
});

test('getJobClassBaselines returns empty array when no per-class baselines exist', function () {
    // Only store aggregate
    $aggregate = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );
    $this->repo->storeBaseline($aggregate);

    $baselines = $this->repo->getJobClassBaselines('redis', 'default');
    expect($baselines)->toBe([]);
});

// --- hasRecentBaseline ---

test('hasRecentBaseline returns true when baseline is recent', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($baseline);

    expect($this->repo->hasRecentBaseline('redis', 'default'))->toBeTrue();
});

test('hasRecentBaseline returns false when baseline is old', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now()->subDays(2),
    );

    $this->repo->storeBaseline($baseline);

    expect($this->repo->hasRecentBaseline('redis', 'default', 86400))->toBeFalse();
});

test('hasRecentBaseline returns false when no baseline exists', function () {
    expect($this->repo->hasRecentBaseline('redis', 'default'))->toBeFalse();
});

// --- deleteBaseline ---

test('deleteBaseline removes all baselines for a queue', function () {
    // Store aggregate
    $aggregate = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    // Store per-class
    $perClass = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\\Jobs\\SendEmail',
        cpuPercentPerJob: 3.0,
        memoryMbPerJob: 128.0,
        avgDurationMs: 200.0,
        sampleCount: 50,
        confidenceScore: 0.75,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($aggregate);
    $this->repo->storeBaseline($perClass);

    // Verify they exist
    expect($this->repo->getBaseline('redis', 'default'))->not->toBeNull();
    expect($this->repo->getJobClassBaseline('redis', 'default', 'App\\Jobs\\SendEmail'))->not->toBeNull();

    $this->repo->deleteBaseline('redis', 'default');

    expect($this->repo->getBaseline('redis', 'default'))->toBeNull();
    expect($this->repo->getJobClassBaseline('redis', 'default', 'App\\Jobs\\SendEmail'))->toBeNull();
});

test('deleteBaseline does not affect other queues', function () {
    $baseline1 = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    $baseline2 = new BaselineData(
        connection: 'redis',
        queue: 'emails',
        jobClass: '',
        cpuPercentPerJob: 5.0,
        memoryMbPerJob: 256.0,
        avgDurationMs: 500.0,
        sampleCount: 200,
        confidenceScore: 0.9,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($baseline1);
    $this->repo->storeBaseline($baseline2);

    $this->repo->deleteBaseline('redis', 'default');

    expect($this->repo->getBaseline('redis', 'default'))->toBeNull();
    expect($this->repo->getBaseline('redis', 'emails'))->not->toBeNull();
});

// --- cleanup ---

test('cleanup removes old baselines and returns count', function () {
    // Store old baseline
    $old = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now()->subDays(10),
    );

    // Store recent baseline
    $recent = new BaselineData(
        connection: 'sqs',
        queue: 'emails',
        jobClass: '',
        cpuPercentPerJob: 5.0,
        memoryMbPerJob: 256.0,
        avgDurationMs: 500.0,
        sampleCount: 200,
        confidenceScore: 0.9,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($old);
    $this->repo->storeBaseline($recent);

    $deleted = $this->repo->cleanup(86400); // Older than 1 day

    expect($deleted)->toBe(1);
    expect($this->repo->getBaseline('redis', 'default'))->toBeNull();
    expect($this->repo->getBaseline('sqs', 'emails'))->not->toBeNull();
});

test('cleanup returns zero when nothing to clean', function () {
    $baseline = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: '',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now(),
    );

    $this->repo->storeBaseline($baseline);

    expect($this->repo->cleanup(86400))->toBe(0);
});

test('cleanup also removes old per-class baselines', function () {
    $oldPerClass = new BaselineData(
        connection: 'redis',
        queue: 'default',
        jobClass: 'App\\Jobs\\OldJob',
        cpuPercentPerJob: 2.5,
        memoryMbPerJob: 64.0,
        avgDurationMs: 150.0,
        sampleCount: 100,
        confidenceScore: 0.85,
        calculatedAt: now()->subDays(10),
    );

    $this->repo->storeBaseline($oldPerClass);

    $deleted = $this->repo->cleanup(86400);

    expect($deleted)->toBe(1);
    expect($this->repo->getJobClassBaseline('redis', 'default', 'App\\Jobs\\OldJob'))->toBeNull();
});
