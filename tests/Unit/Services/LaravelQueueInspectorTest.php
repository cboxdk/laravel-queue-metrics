<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Services\LaravelQueueInspector;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Log;

function inspectorWithBrokenDriver(string $connection): LaravelQueueInspector
{
    $broken = new class
    {
        public function pendingSize(string $queue): int
        {
            throw new RuntimeException('The specified queue does not exist.');
        }

        public function delayedSize(string $queue): int
        {
            throw new RuntimeException('The specified queue does not exist.');
        }

        public function reservedSize(string $queue): int
        {
            throw new RuntimeException('The specified queue does not exist.');
        }
    };

    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->with($connection)->andReturn($broken);

    return new LaravelQueueInspector($factory);
}

// --- getAllQueues ---

test('getAllQueues does not invent a literal default queue', function () {
    config()->set('queue.connections', [
        'sqs' => ['driver' => 'sqs', 'queue' => 'myapp-sqs'],
    ]);
    config()->set('queue.workers', []);

    $queues = app(LaravelQueueInspector::class)->getAllQueues();

    expect($queues)->toBe(['myapp-sqs']);
});

test('getAllQueues still includes default when a connection is configured with it', function () {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis', 'queue' => 'default'],
    ]);
    config()->set('queue.workers', []);

    expect(app(LaravelQueueInspector::class)->getAllQueues())->toContain('default');
});

test('getAllQueues gathers queues from connection lists and worker configs', function () {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis', 'queue' => 'primary', 'queues' => ['high', 'low']],
    ]);
    config()->set('queue.workers', [
        ['queue' => 'reports,exports'],
    ]);

    $queues = app(LaravelQueueInspector::class)->getAllQueues();

    expect($queues)->toContain('primary', 'high', 'low', 'reports', 'exports')
        ->and($queues)->not->toContain('default');
});

// --- getQueueDepth tolerance ---

test('getQueueDepth reports zeros instead of throwing when the driver cannot read the queue', function () {
    Log::spy();

    $inspector = inspectorWithBrokenDriver('sqs');

    $depth = $inspector->getQueueDepth('sqs', 'missing-queue-a');

    expect($depth->pendingJobs)->toBe(0)
        ->and($depth->reservedJobs)->toBe(0)
        ->and($depth->delayedJobs)->toBe(0)
        ->and($depth->oldestPendingJobAge)->toBeNull()
        ->and($depth->isEmpty())->toBeTrue();
});

test('getQueueDepth logs an unreadable queue once per process, not every cycle', function () {
    Log::spy();

    $inspector = inspectorWithBrokenDriver('sqs');

    $inspector->getQueueDepth('sqs', 'missing-queue-b');
    $inspector->getQueueDepth('sqs', 'missing-queue-b');
    $inspector->getQueueDepth('sqs', 'missing-queue-b');

    Log::shouldHaveReceived('info')
        ->with('Queue depth unavailable; reporting zero until the queue becomes readable', Mockery::type('array'))
        ->once();
});
