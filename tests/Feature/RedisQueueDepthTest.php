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
