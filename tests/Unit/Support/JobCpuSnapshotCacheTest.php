<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobCpuSnapshotCache;

beforeEach(function () {
    JobCpuSnapshotCache::reset();
});

it('returns null for unknown job', function () {
    expect(JobCpuSnapshotCache::get('nonexistent'))->toBeNull();
})->group('functional');

it('stores and retrieves cpu time for a job', function () {
    JobCpuSnapshotCache::store('job-123', 4500.0);

    expect(JobCpuSnapshotCache::get('job-123'))->toBe(4500.0);
})->group('functional');

it('isolates entries by job id', function () {
    JobCpuSnapshotCache::store('job-a', 1000.0);
    JobCpuSnapshotCache::store('job-b', 2000.0);

    expect(JobCpuSnapshotCache::get('job-a'))->toBe(1000.0);
    expect(JobCpuSnapshotCache::get('job-b'))->toBe(2000.0);
})->group('functional');

it('forget removes a single entry', function () {
    JobCpuSnapshotCache::store('job-123', 4500.0);
    JobCpuSnapshotCache::store('job-456', 5500.0);

    JobCpuSnapshotCache::forget('job-123');

    expect(JobCpuSnapshotCache::get('job-123'))->toBeNull();
    expect(JobCpuSnapshotCache::get('job-456'))->toBe(5500.0);
})->group('functional');

it('reset clears all entries', function () {
    JobCpuSnapshotCache::store('job-a', 1000.0);
    JobCpuSnapshotCache::store('job-b', 2000.0);

    JobCpuSnapshotCache::reset();

    expect(JobCpuSnapshotCache::get('job-a'))->toBeNull();
    expect(JobCpuSnapshotCache::get('job-b'))->toBeNull();
})->group('functional');
