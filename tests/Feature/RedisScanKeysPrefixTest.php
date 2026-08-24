<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cbox\LaravelQueueMetrics\Repositories\RedisJobMetricsRepository;
use Cbox\LaravelQueueMetrics\Services\RedisKeyScannerService;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;
use Illuminate\Support\Facades\Redis;

/**
 * The suite runs with a Redis connection prefix (lqm_test_, see TestCase), so
 * these tests pin that scanKeys() results are usable with every other store
 * method. Before the fix the scan returned raw keys carrying the connection
 * prefix, so every follow-up read or delete through the store was prefixed
 * twice and silently missed - cleanup() was a no-op on any prefixed
 * connection, which is Laravel's production default.
 */
beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue-metrics.enabled', true);
    config()->set('queue-metrics.storage.driver', 'redis');
    config()->set('queue-metrics.storage.connection', 'default');

    Redis::connection('default')->flushdb();
});

it('returns scanned keys that read back through the store', function () {
    $store = app(RedisMetricsStore::class);

    $keyA = $store->key('scanprefix', 'a');
    $keyB = $store->key('scanprefix', 'b');
    $store->setHash($keyA, ['field' => '1']);
    $store->setHash($keyB, ['field' => '2']);

    $keys = $store->scanKeys($store->key('scanprefix', '*'));
    sort($keys);

    expect($keys)->toBe([$keyA, $keyB])
        ->and($store->getHash($keys[0]))->toBe(['field' => '1'])
        ->and($store->getHashField($keys[1], 'field'))->toBe('2');
})->group('redis');

it('cleanup deletes stale job metrics on a prefixed connection', function () {
    $store = app(RedisMetricsStore::class);
    $repository = new RedisJobMetricsRepository($store);

    $staleKey = $store->key('jobs', 'redis', 'default', 'App\\Jobs\\Stale');
    $freshKey = $store->key('jobs', 'redis', 'default', 'App\\Jobs\\Fresh');
    $store->setHash($staleKey, ['last_processed_at' => (string) Carbon::now()->subDays(2)->timestamp]);
    $store->setHash($freshKey, ['last_processed_at' => (string) Carbon::now()->timestamp]);

    $deleted = $repository->cleanup(86400);

    expect($deleted)->toBe(1)
        ->and($store->getHash($staleKey))->toBe([])
        ->and($store->getHash($freshKey))->not->toBe([]);
})->group('redis');

it('discovers queues from scanned keys on a prefixed connection', function () {
    $store = app(RedisMetricsStore::class);
    $store->setHash($store->key('queued', 'redis', 'reports', 'App\\Jobs\\Report'), ['total_queued' => '1']);
    $store->setHash($store->key('jobs', 'redis', 'emails', 'App\\Jobs\\Email'), ['total_processed' => '3']);

    $discovered = app(RedisKeyScannerService::class)->scanAndParseKeys(
        $store->key('jobs', '*', '*', '*'),
        $store->key('queued', '*', '*', '*'),
        function (string $keyWithoutPrefix): ?array {
            $parts = explode(':', $keyWithoutPrefix);
            if (count($parts) < 4) {
                return null;
            }

            return ['connection' => $parts[1], 'queue' => $parts[2]];
        },
    );

    expect($discovered)->toHaveKeys(['redis:reports', 'redis:emails']);
})->group('redis');
