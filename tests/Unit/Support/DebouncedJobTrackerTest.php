<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\DebouncedJobTracker;

beforeEach(function () {
    DebouncedJobTracker::flush();
});

it('marks a job as debounced', function () {
    DebouncedJobTracker::mark('job-123');

    expect(DebouncedJobTracker::wasDebounced('job-123'))->toBeTrue();
})->group('functional');

it('returns false for unmarked jobs', function () {
    expect(DebouncedJobTracker::wasDebounced('job-456'))->toBeFalse();
})->group('functional');

it('consumes the mark on check so it cannot be double-read', function () {
    DebouncedJobTracker::mark('job-789');

    expect(DebouncedJobTracker::wasDebounced('job-789'))->toBeTrue();
    expect(DebouncedJobTracker::wasDebounced('job-789'))->toBeFalse();
})->group('functional');

it('tracks multiple jobs independently', function () {
    DebouncedJobTracker::mark('job-a');
    DebouncedJobTracker::mark('job-b');

    expect(DebouncedJobTracker::wasDebounced('job-a'))->toBeTrue();
    expect(DebouncedJobTracker::wasDebounced('job-b'))->toBeTrue();
    expect(DebouncedJobTracker::wasDebounced('job-a'))->toBeFalse();
})->group('functional');

it('flushes all tracked jobs', function () {
    DebouncedJobTracker::mark('job-x');
    DebouncedJobTracker::mark('job-y');

    DebouncedJobTracker::flush();

    expect(DebouncedJobTracker::wasDebounced('job-x'))->toBeFalse();
    expect(DebouncedJobTracker::wasDebounced('job-y'))->toBeFalse();
})->group('functional');
