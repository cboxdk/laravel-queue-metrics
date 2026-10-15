<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Actions\CalculateAggregatedJobMetricsAction;
use Cbox\LaravelQueueMetrics\Actions\CalculateBaselinesAction;
use Cbox\LaravelQueueMetrics\Actions\CalculateQueueMetricsAction;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\JobMetricsRepository;
use Cbox\LaravelQueueMetrics\Repositories\Contracts\QueueMetricsRepository;
use Cbox\LaravelQueueMetrics\Support\RedisDiscoveryIndex;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

/**
 * Per-member ageing of the Redis discovery sets.
 *
 * The aggregated TTL is 7 days by default, so "6 days" is inside the
 * retention period and "8 days" is past it.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        $this->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');
    config()->set('queue-metrics.storage.ttl.aggregated', 604800);

    Redis::connection('default')->flushdb();

    $this->store = app(RedisMetricsStore::class);
    $this->jobs = app(JobMetricsRepository::class);
    $this->queues = app(QueueMetricsRepository::class);
    $this->redis = Redis::connection('default');

    $this->jobsSeenKey = $this->store->key('discovery', 'jobs', 'seen');
    $this->queuesSeenKey = $this->store->key('discovery', 'queues', 'seen');
    $this->jobsLegacyKey = $this->store->key('discovery', 'jobs');
    $this->queuesLegacyKey = $this->store->key('discovery', 'queues');
});

function discoveryStartJob(string $jobClass, string $queue = 'default', string $connection = 'redis'): void
{
    app(JobMetricsRepository::class)->recordStart(uniqid('job-', true), $jobClass, $connection, $queue, Carbon::now());
}

/**
 * @return list<string>
 */
function discoveryJobClasses(): array
{
    $classes = array_column(app(JobMetricsRepository::class)->listJobs(), 'jobClass');
    sort($classes);

    return $classes;
}

/**
 * @return list<string>
 */
function discoveryQueueNames(): array
{
    $queues = array_column(app(QueueMetricsRepository::class)->listQueues(), 'queue');
    sort($queues);

    return $queues;
}

// --- Writes ---

it('records a job start in the last-seen sorted sets, scored with the current time', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

    discoveryStartJob('App\\Jobs\\SendEmail', 'emails');

    expect($this->redis->zscore($this->jobsSeenKey, 'redis:emails:App\\Jobs\\SendEmail'))->toEqual(1_800_000_000)
        ->and($this->redis->zscore($this->queuesSeenKey, 'redis:emails'))->toEqual(1_800_000_000);

    Carbon::setTestNow();
})->group('redis');

it('no longer writes the legacy plain-set discovery keys', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->jobs->markJobDiscovered('redis', 'default', 'App\\Jobs\\Other');
    $this->queues->markQueueDiscovered('redis', 'other');

    expect($this->store->exists($this->jobsLegacyKey))->toBeFalse()
        ->and($this->store->exists($this->queuesLegacyKey))->toBeFalse();
})->group('redis');

it('gives the last-seen sorted sets the aggregated TTL as a whole-key safety net', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');

    $jobsTtl = $this->redis->ttl($this->jobsSeenKey);
    $queuesTtl = $this->redis->ttl($this->queuesSeenKey);

    expect($jobsTtl)->toBeGreaterThan(604800 - 10)->toBeLessThanOrEqual(604800)
        ->and($queuesTtl)->toBeGreaterThan(604800 - 10)->toBeLessThanOrEqual(604800);
})->group('redis');

it('marks jobs and queues discovered through the explicit API in the last-seen sorted sets', function () {
    $this->jobs->markJobDiscovered('sqs', 'reports:nightly', 'App\\Jobs\\Report');
    $this->queues->markQueueDiscovered('sqs', 'reports:nightly');

    expect($this->jobs->listJobs())->toBe([
        ['connection' => 'sqs', 'queue' => 'reports:nightly', 'jobClass' => 'App\\Jobs\\Report'],
    ])->and($this->queues->listQueues())->toBe([
        ['connection' => 'sqs', 'queue' => 'reports:nightly'],
    ])->and($this->redis->ttl($this->jobsSeenKey))->toBeGreaterThan(0)
        ->and($this->redis->ttl($this->queuesSeenKey))->toBeGreaterThan(0);
})->group('redis');

it('lists a job and queue started many times only once', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');
    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->jobs->markJobDiscovered('redis', 'default', 'App\\Jobs\\SendEmail');

    expect($this->jobs->listJobs())->toHaveCount(1)
        ->and($this->queues->listQueues())->toHaveCount(1);
})->group('redis');

// --- Ageing ---

it('ages a job class out of discovery once it has not run for the aggregated TTL, while others stay', function () {
    discoveryStartJob('App\\Jobs\\Retired', 'legacy');

    $this->travel(6)->days();
    discoveryStartJob('App\\Jobs\\Active');

    $this->travel(2)->days();

    expect(discoveryJobClasses())->toBe(['App\\Jobs\\Active'])
        ->and($this->redis->zscore($this->jobsSeenKey, 'redis:legacy:App\\Jobs\\Retired'))->toBeFalsy();
})->group('redis');

it('ages a queue out of discovery once it has not been used for the aggregated TTL, while others stay', function () {
    discoveryStartJob('App\\Jobs\\SendEmail', 'retired');

    $this->travel(6)->days();
    discoveryStartJob('App\\Jobs\\SendEmail', 'default');

    $this->travel(2)->days();

    expect(discoveryQueueNames())->toBe(['default'])
        ->and($this->redis->zscore($this->queuesSeenKey, 'redis:retired'))->toBeFalsy();
})->group('redis');

it('ages out a member registered through the explicit API too', function () {
    $this->jobs->markJobDiscovered('redis', 'default', 'App\\Jobs\\Retired');
    $this->queues->markQueueDiscovered('redis', 'retired');

    $this->travel(8)->days();

    expect($this->jobs->listJobs())->toBe([])
        ->and($this->queues->listQueues())->toBe([]);
})->group('redis');

it('expires a member exactly one retention period after it was last seen, as the database driver does', function () {
    config()->set('queue-metrics.storage.ttl.aggregated', 100);
    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));

    discoveryStartJob('App\\Jobs\\SendEmail');

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_099));
    expect(discoveryJobClasses())->toBe(['App\\Jobs\\SendEmail'])
        ->and(discoveryQueueNames())->toBe(['default']);

    Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_100));
    expect(discoveryJobClasses())->toBe([])
        ->and(discoveryQueueNames())->toBe([]);

    Carbon::setTestNow();
})->group('redis');

it('refreshes a member that is seen again before it ages out', function () {
    discoveryStartJob('App\\Jobs\\SendEmail', 'emails');

    $this->travel(6)->days();
    discoveryStartJob('App\\Jobs\\SendEmail', 'emails');

    $this->travel(6)->days();

    expect(discoveryJobClasses())->toBe(['App\\Jobs\\SendEmail'])
        ->and(discoveryQueueNames())->toBe(['emails'])
        ->and((int) $this->redis->zscore($this->jobsSeenKey, 'redis:emails:App\\Jobs\\SendEmail'))
        ->toBe(Carbon::now()->subDays(6)->getTimestamp());
})->group('redis');

it('rediscovers an aged-out member the next time it is seen', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');

    $this->travel(8)->days();
    expect(discoveryJobClasses())->toBe([]);

    discoveryStartJob('App\\Jobs\\SendEmail');
    expect(discoveryJobClasses())->toBe(['App\\Jobs\\SendEmail']);
})->group('redis');

// --- Downstream consumers ---

it('stops calculating queue metrics for a queue that has aged out of discovery', function () {
    discoveryStartJob('App\\Jobs\\SendEmail', 'retired');

    $this->travel(6)->days();
    discoveryStartJob('App\\Jobs\\SendEmail', 'default');

    expect(app(CalculateQueueMetricsAction::class)->executeForAllQueues())->toBe(2);

    $this->travel(2)->days();

    expect(app(CalculateQueueMetricsAction::class)->executeForAllQueues())->toBe(1);
})->group('redis');

it('stops aggregating a job class over a queue it has not run on within the aggregated TTL', function () {
    discoveryStartJob('App\\Jobs\\SendEmail', 'old-queue');

    $this->travel(6)->days();
    discoveryStartJob('App\\Jobs\\SendEmail', 'new-queue');

    $this->travel(2)->days();

    $byQueue = app(CalculateAggregatedJobMetricsAction::class)->execute('App\\Jobs\\SendEmail')->byQueue;

    expect(array_column($byQueue, 'queue'))->toBe(['new-queue']);
})->group('redis');

it('stops calculating baselines for a queue that has aged out of discovery', function () {
    discoveryStartJob('App\\Jobs\\SendEmail', 'retired');

    $this->travel(8)->days();

    expect(app(CalculateBaselinesAction::class)->execute()['queues_processed'])->toBe(0);
})->group('redis');

// --- Rolling-deploy transition: legacy plain sets ---

it('merges members an older worker wrote to the legacy plain sets', function () {
    discoveryStartJob('App\\Jobs\\New', 'new');
    $this->store->addToSet($this->jobsLegacyKey, ['redis:old:App\\Jobs\\Old']);
    $this->store->addToSet($this->queuesLegacyKey, ['redis:old']);

    expect(discoveryJobClasses())->toBe(['App\\Jobs\\New', 'App\\Jobs\\Old'])
        ->and(discoveryQueueNames())->toBe(['new', 'old']);
})->group('redis');

it('lists a member present in both the legacy set and the sorted set once', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->store->addToSet($this->jobsLegacyKey, ['redis:default:App\\Jobs\\SendEmail']);
    $this->store->addToSet($this->queuesLegacyKey, ['redis:default']);

    expect($this->jobs->listJobs())->toBe([
        ['connection' => 'redis', 'queue' => 'default', 'jobClass' => 'App\\Jobs\\SendEmail'],
    ])->and($this->queues->listQueues())->toBe([
        ['connection' => 'redis', 'queue' => 'default'],
    ]);
})->group('redis');

it('does not refresh the TTL of the legacy sets, so they expire once older workers stop', function () {
    $this->store->addToSet($this->jobsLegacyKey, ['redis:old:App\\Jobs\\Old']);
    $this->store->addToSet($this->queuesLegacyKey, ['redis:old']);
    $this->store->expire($this->jobsLegacyKey, 100);
    $this->store->expire($this->queuesLegacyKey, 100);

    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->jobs->markJobDiscovered('redis', 'default', 'App\\Jobs\\SendEmail');
    $this->queues->markQueueDiscovered('redis', 'default');
    $this->jobs->listJobs();
    $this->queues->listQueues();

    expect($this->redis->ttl($this->jobsLegacyKey))->toBeGreaterThan(0)->toBeLessThanOrEqual(100)
        ->and($this->redis->ttl($this->queuesLegacyKey))->toBeGreaterThan(0)->toBeLessThanOrEqual(100);
})->group('redis');

it('gives a legacy set written without a TTL the retention period when it is read', function () {
    $this->store->addToSet($this->jobsLegacyKey, ['redis:old:App\\Jobs\\Old']);
    $this->store->addToSet($this->queuesLegacyKey, ['redis:old']);

    expect($this->redis->ttl($this->jobsLegacyKey))->toBe(-1);

    $this->jobs->listJobs();
    $this->queues->listQueues();

    expect($this->redis->ttl($this->jobsLegacyKey))->toBeGreaterThan(604700)->toBeLessThanOrEqual(604800)
        ->and($this->redis->ttl($this->queuesLegacyKey))->toBeGreaterThan(604700)->toBeLessThanOrEqual(604800)
        ->and(discoveryJobClasses())->toBe(['App\\Jobs\\Old']);
})->group('redis');

it('does not extend a legacy set that already has a TTL when it is read repeatedly', function () {
    $this->store->addToSet($this->queuesLegacyKey, ['redis:old']);
    $this->store->expire($this->queuesLegacyKey, 50);

    $this->queues->listQueues();
    $this->queues->listQueues();

    expect($this->redis->ttl($this->queuesLegacyKey))->toBeGreaterThan(0)->toBeLessThanOrEqual(50);
})->group('redis');

it('removes an unparseable member from the legacy set and keeps its parseable neighbours', function () {
    $this->store->addToSet($this->jobsLegacyKey, [
        'redis:reports:nightly:App\\Jobs\\Report',
        'redis:default:App\\Jobs\\Old',
    ]);

    expect($this->jobs->listJobs())->toBe([
        ['connection' => 'redis', 'queue' => 'default', 'jobClass' => 'App\\Jobs\\Old'],
    ])->and($this->store->getSetMembers($this->jobsLegacyKey))->toBe(['redis:default:App\\Jobs\\Old']);
})->group('redis');

it('removes an unparseable member from the last-seen sorted set', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->store->addToSortedSet($this->jobsSeenKey, ['redis:a~b:App\\Jobs\\Tilde' => Carbon::now()->getTimestamp()]);

    expect(discoveryJobClasses())->toBe(['App\\Jobs\\SendEmail'])
        ->and($this->store->getSortedSetByRank($this->jobsSeenKey, 0, -1))->toBe(['redis:default:App\\Jobs\\SendEmail']);
})->group('redis');

// --- Type safety: set commands never reach a sorted set, or vice versa ---

it('keeps every discovery key at the Redis type it was created with across the whole lifecycle', function () {
    $this->store->addToSet($this->jobsLegacyKey, ['redis:old:App\\Jobs\\Old', 'redis:reports:nightly:App\\Jobs\\Report']);
    $this->store->addToSet($this->queuesLegacyKey, ['redis:old']);

    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->jobs->markJobDiscovered('redis', 'default', 'App\\Jobs\\Other');
    $this->queues->markQueueDiscovered('redis', 'other');
    $this->jobs->listJobs();
    $this->queues->listQueues();
    $this->travel(8)->days();
    $this->jobs->listJobs();
    $this->queues->listQueues();
    discoveryStartJob('App\\Jobs\\SendEmail');

    expect($this->store->command('type', [$this->jobsSeenKey]))->toBe(\Redis::REDIS_ZSET)
        ->and($this->store->command('type', [$this->queuesSeenKey]))->toBe(\Redis::REDIS_ZSET)
        ->and($this->store->command('type', [$this->jobsLegacyKey]))->toBe(\Redis::REDIS_SET)
        ->and($this->store->command('type', [$this->queuesLegacyKey]))->toBe(\Redis::REDIS_SET);
})->group('redis');

it('ignores a legacy discovery key that holds something other than a set', function () {
    $this->store->set($this->jobsLegacyKey, 'not-a-set');
    $this->store->addToSortedSet($this->queuesLegacyKey, ['redis:zset' => 1]);

    discoveryStartJob('App\\Jobs\\SendEmail');
    $this->store->addToSortedSet($this->jobsSeenKey, ['redis:a~b:App\\Jobs\\Tilde' => Carbon::now()->getTimestamp()]);

    expect(discoveryJobClasses())->toBe(['App\\Jobs\\SendEmail'])
        ->and(discoveryQueueNames())->toBe(['default'])
        ->and($this->store->get($this->jobsLegacyKey))->toBe('not-a-set');
})->group('redis');

it('does not create the legacy keys by reading them', function () {
    discoveryStartJob('App\\Jobs\\SendEmail');

    $this->jobs->listJobs();
    $this->queues->listQueues();

    expect($this->store->exists($this->jobsLegacyKey))->toBeFalse()
        ->and($this->store->exists($this->queuesLegacyKey))->toBeFalse();
})->group('redis');

// --- RedisMetricsStore::isSet ---

it('reports whether a key holds a plain set', function () {
    $this->store->addToSet('lqm:is-set:set', ['a']);
    $this->store->addToSortedSet('lqm:is-set:zset', ['a' => 1]);
    $this->store->set('lqm:is-set:string', 'a');
    $this->store->setHash('lqm:is-set:hash', ['a' => 1]);

    expect($this->store->isSet('lqm:is-set:set'))->toBeTrue()
        ->and($this->store->isSet('lqm:is-set:zset'))->toBeFalse()
        ->and($this->store->isSet('lqm:is-set:string'))->toBeFalse()
        ->and($this->store->isSet('lqm:is-set:hash'))->toBeFalse()
        ->and($this->store->isSet('lqm:is-set:missing'))->toBeFalse();
})->group('redis');

// --- RedisDiscoveryIndex ---

it('builds its keys under the configured storage prefix', function () {
    config()->set('queue-metrics.storage.prefix', 'custom_prefix');
    $index = new RedisDiscoveryIndex(new RedisMetricsStore, 'jobs');

    expect($index->seenKey())->toBe('custom_prefix:discovery:jobs:seen')
        ->and($index->legacyKey())->toBe('custom_prefix:discovery:jobs')
        ->and($index->retentionSeconds())->toBe(604800);
})->group('redis');

it('forgets members from both the sorted set and the legacy set', function () {
    $index = new RedisDiscoveryIndex($this->store, 'jobs');
    $index->touch($this->store, 'redis:default:App\\Jobs\\A');
    $index->touch($this->store, 'redis:default:App\\Jobs\\B');
    $this->store->addToSet($this->jobsLegacyKey, ['redis:default:App\\Jobs\\A', 'redis:default:App\\Jobs\\C']);

    $index->forget(['redis:default:App\\Jobs\\A']);
    $index->forget([]);

    $members = $index->members();
    sort($members);

    expect($members)->toBe(['redis:default:App\\Jobs\\B', 'redis:default:App\\Jobs\\C']);
})->group('redis');
