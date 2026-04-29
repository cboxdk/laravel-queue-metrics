<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\CpuSnapshotCache;

beforeEach(function () {
    CpuSnapshotCache::reset();
});

it('returns null for unknown worker', function () {
    expect(CpuSnapshotCache::get('nonexistent'))->toBeNull();
})->group('functional');

it('stores and retrieves a snapshot', function () {
    CpuSnapshotCache::store('worker-1', 1500.0, 1000000.0);

    $snapshot = CpuSnapshotCache::get('worker-1');

    expect($snapshot)->toBe([
        'cpu_time_ms' => 1500.0,
        'wall_time' => 1000000.0,
    ]);
})->group('functional');

it('overwrites previous snapshot for same worker', function () {
    CpuSnapshotCache::store('worker-1', 1000.0, 1000000.0);
    CpuSnapshotCache::store('worker-1', 2000.0, 1000010.0);

    $snapshot = CpuSnapshotCache::get('worker-1');

    expect($snapshot['cpu_time_ms'])->toBe(2000.0);
    expect($snapshot['wall_time'])->toBe(1000010.0);
})->group('functional');

it('evicts entries older than 300 seconds on store', function () {
    $baseTime = 1000000.0;

    // Store two workers at base time
    CpuSnapshotCache::store('worker-old', 100.0, $baseTime);
    CpuSnapshotCache::store('worker-recent', 200.0, $baseTime + 200);

    // Both should exist
    expect(CpuSnapshotCache::get('worker-old'))->not->toBeNull();
    expect(CpuSnapshotCache::get('worker-recent'))->not->toBeNull();

    // Store a new entry 301 seconds after worker-old's timestamp
    // This should evict worker-old (age = 301s > 300s) but keep worker-recent (age = 101s)
    CpuSnapshotCache::store('worker-new', 300.0, $baseTime + 301);

    expect(CpuSnapshotCache::get('worker-old'))->toBeNull();
    expect(CpuSnapshotCache::get('worker-recent'))->not->toBeNull();
    expect(CpuSnapshotCache::get('worker-new'))->not->toBeNull();
})->group('functional');

it('keeps entries exactly at the 300 second boundary', function () {
    $baseTime = 1000000.0;

    CpuSnapshotCache::store('worker-boundary', 100.0, $baseTime);

    // Store at exactly 300 seconds later — worker-boundary's age is exactly 300s
    // cutoff = wallTime - 300 = baseTime, so wall_time < cutoff is false (equal, not less)
    CpuSnapshotCache::store('worker-trigger', 200.0, $baseTime + 300);

    expect(CpuSnapshotCache::get('worker-boundary'))->not->toBeNull();
})->group('functional');

it('reset clears all entries', function () {
    CpuSnapshotCache::store('worker-1', 100.0, 1000000.0);
    CpuSnapshotCache::store('worker-2', 200.0, 1000000.0);

    CpuSnapshotCache::reset();

    expect(CpuSnapshotCache::get('worker-1'))->toBeNull();
    expect(CpuSnapshotCache::get('worker-2'))->toBeNull();
})->group('functional');
