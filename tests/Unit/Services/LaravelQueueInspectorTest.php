<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Services\LaravelQueueInspector;

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
