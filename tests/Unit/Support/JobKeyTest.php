<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\JobKey;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;

it('uses the driver job id when there is one', function () {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getJobId')->andReturn('42');
    $job->shouldNotReceive('uuid');

    expect(JobKey::of($job))->toBe('42');
});

it('uses the payload uuid for a sync job, which has no job id', function () {
    $job = new SyncJob(app(), json_encode(['uuid' => 'a1b2c3', 'job' => 'Handler@call']), 'sync', 'default');

    expect($job->getJobId())->toBe('')
        ->and(JobKey::of($job))->toBe('a1b2c3');
});

it('gives two sync jobs alive at the same time different keys', function () {
    $outer = new SyncJob(app(), json_encode(['uuid' => 'outer', 'job' => 'Handler@call']), 'sync', 'default');
    $inner = new SyncJob(app(), json_encode(['uuid' => 'inner', 'job' => 'Handler@call']), 'sync', 'default');

    expect(JobKey::of($outer))->not->toBe(JobKey::of($inner));
});

it('falls back to the job object identity without a job id or uuid', function () {
    $first = new SyncJob(app(), json_encode(['job' => 'Handler@call']), 'sync', 'default');
    $second = new SyncJob(app(), json_encode(['job' => 'Handler@call']), 'sync', 'default');

    expect(JobKey::of($first))->toBe(JobKey::of($first))
        ->and(JobKey::of($first))->not->toBe(JobKey::of($second))
        ->and(JobKey::of($first))->not->toBe('');
});
