<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\DataTransferObjects\QueueDepthData;
use Cbox\LaravelQueueMetrics\Services\LaravelQueueInspector;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    if (! getenv('REDIS_AVAILABLE')) {
        test()->markTestSkipped('Requires Redis - run with redis group');
    }

    config()->set('queue.connections.redis', [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => 'default',
        'retry_after' => 90,
    ]);

    Redis::connection('default')->flushdb();
});

/**
 * The reflection fallback path previously guarded on method_exists() for
 * llen/zcard/lindex/zrange, which is always false on Laravel's Redis
 * connections (commands are proxied through __call), so it silently
 * reported zero depth for every queue. These tests pin the repaired path
 * against a real Redis.
 */
it('reads real pending and delayed depth through the redis fallback path', function () {
    $queue = app('queue')->connection('redis');
    $queue->pushRaw('{"job":"a","pushedAt":'.now()->subSeconds(30)->getTimestamp().'}', 'depth-fallback');
    $queue->pushRaw('{"job":"b"}', 'depth-fallback');
    $queue->pushRaw('{"job":"c"}', 'depth-fallback');
    $queue->later(60, 'DelayedJob', '', 'depth-fallback');

    $depth = readDepthViaRedisFallback($queue, 'depth-fallback');

    expect($depth->pendingJobs)->toBe(3)
        ->and($depth->reservedJobs)->toBe(0)
        ->and($depth->delayedJobs)->toBe(1)
        ->and($depth->oldestPendingJobAge)->not->toBeNull()
        ->and($depth->oldestDelayedJobAge)->not->toBeNull();
})->group('redis');

it('reads zero depth for a genuinely empty queue through the redis fallback path', function () {
    $queue = app('queue')->connection('redis');

    $depth = readDepthViaRedisFallback($queue, 'depth-empty');

    expect($depth->pendingJobs)->toBe(0)
        ->and($depth->isEmpty())->toBeTrue();
})->group('redis');

function readDepthViaRedisFallback(object $queue, string $queueName): QueueDepthData
{
    $inspector = new LaravelQueueInspector(app('queue'));

    $method = new ReflectionMethod($inspector, 'getRedisQueueDepth');

    /** @var QueueDepthData $depth */
    $depth = $method->invoke($inspector, $queue, 'redis', $queueName);

    return $depth;
}

/**
 * Laravel only moves a due delayed job to the ready set inside a worker's
 * pop(), so with no worker on the queue it reads as neither pending nor
 * reserved and stays put. delayedDueNowJobs is the only signal that the work
 * exists, which is what lets a consumer decide the queue needs a worker.
 */
it('counts delayed jobs that have come due but nothing has migrated', function () {
    $queue = app('queue')->connection('redis');
    $queue->later(-30, 'DueJob', '', 'depth-due');
    $queue->later(3600, 'FutureJob', '', 'depth-due');

    $depth = readDepthViaRedisFallback($queue, 'depth-due');

    expect($depth->pendingJobs)->toBe(0)
        ->and($depth->delayedJobs)->toBe(2)
        ->and($depth->delayedDueNowJobs)->toBe(1);
})->group('redis');

it('counts no delayed job as due while every one is still in the future', function () {
    $queue = app('queue')->connection('redis');
    $queue->later(3600, 'FutureJob', '', 'depth-not-due');

    $depth = readDepthViaRedisFallback($queue, 'depth-not-due');

    expect($depth->delayedJobs)->toBe(1)
        ->and($depth->delayedDueNowJobs)->toBe(0);
})->group('redis');

it('reports the due count through the native size API path too', function () {
    $queue = app('queue')->connection('redis');
    $queue->later(-30, 'DueJob', '', 'depth-due-native');

    $inspector = new LaravelQueueInspector(app('queue'));
    $method = new ReflectionMethod($inspector, 'getDepthNativeApi');

    /** @var QueueDepthData $depth */
    $depth = $method->invoke($inspector, $queue, 'redis', 'depth-due-native');

    expect($depth->delayedJobs)->toBe(1)
        ->and($depth->delayedDueNowJobs)->toBe(1);
})->skip(
    fn (): bool => ! method_exists(app('queue')->connection('redis'), 'delayedSize'),
    'Requires the Laravel 12.19+ native queue size API',
)->group('redis');

it('leaves the due count at zero for a driver with no separate delayed store', function () {
    $depth = app(LaravelQueueInspector::class)->getQueueDepth('database', 'default');

    expect($depth->delayedDueNowJobs)->toBe(0);
})->group('redis');
