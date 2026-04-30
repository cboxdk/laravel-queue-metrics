<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobMemorySnapshotCache;

beforeEach(function () {
    JobMemorySnapshotCache::reset();
});

it('returns null for unknown job', function () {
    expect(JobMemorySnapshotCache::get('nonexistent'))->toBeNull();
})->group('functional');

it('stores and retrieves memory for a job', function () {
    JobMemorySnapshotCache::store('job-123', 64.5);

    expect(JobMemorySnapshotCache::get('job-123'))->toBe(64.5);
})->group('functional');

it('isolates entries by job id', function () {
    JobMemorySnapshotCache::store('job-a', 50.0);
    JobMemorySnapshotCache::store('job-b', 120.0);

    expect(JobMemorySnapshotCache::get('job-a'))->toBe(50.0);
    expect(JobMemorySnapshotCache::get('job-b'))->toBe(120.0);
})->group('functional');

it('forget removes a single entry', function () {
    JobMemorySnapshotCache::store('job-123', 64.5);
    JobMemorySnapshotCache::store('job-456', 80.0);

    JobMemorySnapshotCache::forget('job-123');

    expect(JobMemorySnapshotCache::get('job-123'))->toBeNull();
    expect(JobMemorySnapshotCache::get('job-456'))->toBe(80.0);
})->group('functional');

it('reset clears all entries', function () {
    JobMemorySnapshotCache::store('job-a', 50.0);
    JobMemorySnapshotCache::store('job-b', 120.0);

    JobMemorySnapshotCache::reset();

    expect(JobMemorySnapshotCache::get('job-a'))->toBeNull();
    expect(JobMemorySnapshotCache::get('job-b'))->toBeNull();
})->group('functional');
