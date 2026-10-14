<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobStartTimeCache;

beforeEach(function () {
    JobStartTimeCache::reset();
});

it('stores and forgets a job start time', function () {
    JobStartTimeCache::store('job-1', 1000.5);

    expect(JobStartTimeCache::get('job-1'))->toBe(1000.5);

    JobStartTimeCache::forget('job-1');

    expect(JobStartTimeCache::get('job-1'))->toBeNull();
});

it('keeps an old entry however long ago it was stored', function () {
    JobStartTimeCache::store('long-job', microtime(true) - 86_400);
    JobStartTimeCache::store('next-job', microtime(true));

    expect(JobStartTimeCache::get('long-job'))->not->toBeNull();
});

it('bounds abandoned entries by count, dropping the oldest first', function () {
    foreach (range(1, 150) as $i) {
        JobStartTimeCache::store("job-{$i}", (float) $i);
    }

    expect(JobStartTimeCache::get('job-50'))->toBeNull()
        ->and(JobStartTimeCache::get('job-51'))->toBe(51.0)
        ->and(JobStartTimeCache::get('job-150'))->toBe(150.0);
});
