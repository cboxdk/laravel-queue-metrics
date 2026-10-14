<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\JobMetricsQueryService;
use Cbox\LaravelQueueMetrics\Services\QueueMetricsQueryService;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    Redis::connection('default')->flushdb();
});

it('discovers a queue whose name contains a colon under its full name', function () {
    $repository = app(JobMetricsRepository::class);
    $repository->recordStart('job-1', 'App\\Jobs\\Report', 'redis', 'reports:nightly', Carbon::now());
    $repository->recordCompletion('job-1', 'App\\Jobs\\Report', 'redis', 'reports:nightly', 120.0, 64.0, 30.0, Carbon::now());

    $queues = app(QueueMetricsQueryService::class)->getAllQueuesWithMetrics();
    $queueNames = array_map(fn (array $queue): string => $queue['queue'], array_values($queues));

    expect($queueNames)->toContain('reports:nightly')
        ->and($queueNames)->not->toContain('reports');
})->group('redis');

it('lists jobs on a queue whose name contains a colon with the right queue and class', function () {
    $repository = app(JobMetricsRepository::class);
    $repository->recordStart('job-1', 'App\\Jobs\\Report', 'redis', 'reports:nightly', Carbon::now());
    $repository->recordCompletion('job-1', 'App\\Jobs\\Report', 'redis', 'reports:nightly', 120.0, 64.0, 30.0, Carbon::now());

    $jobs = app(JobMetricsQueryService::class)->getAllJobsWithMetrics();

    expect(collect($jobs)->first(fn (array $job): bool => $job['queue'] === 'reports:nightly'))->not->toBeNull()
        ->and($repository->listJobs())->toContain([
            'connection' => 'redis',
            'queue' => 'reports:nightly',
            'jobClass' => 'App\\Jobs\\Report',
        ]);
})->group('redis');

it('drops a job discovery member written before key segments were escaped', function () {
    $repository = app(JobMetricsRepository::class);
    $store = app(RedisMetricsStore::class);
    $legacyKey = $store->key('discovery', 'jobs');
    $seenKey = $store->key('discovery', 'jobs', 'seen');

    $repository->recordStart('job-1', 'App\\Jobs\\Report', 'redis', 'reports:nightly', Carbon::now());
    $store->addToSet($legacyKey, ['redis:reports:nightly:App\\Jobs\\Report']);

    expect($repository->listJobs())->toBe([
        ['connection' => 'redis', 'queue' => 'reports:nightly', 'jobClass' => 'App\\Jobs\\Report'],
    ])->and($store->getSetMembers($legacyKey))->toBe([])
        ->and($store->getSortedSetByRank($seenKey, 0, -1))->toBe(['redis:reports~cnightly:App\\Jobs\\Report']);
})->group('redis');
