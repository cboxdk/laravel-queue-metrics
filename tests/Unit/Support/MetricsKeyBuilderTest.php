<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\MetricsKeyBuilder;
use Cbox\LaravelQueueMetrics\Support\RedisMetricsStore;

it('leaves keys of ordinary segments unchanged', function () {
    expect((new MetricsKeyBuilder('queue_metrics'))->key('jobs', 'redis', 'default', 'App\\Jobs\\SendEmail'))
        ->toBe('queue_metrics:jobs:redis:default:App\\Jobs\\SendEmail');
});

it('escapes segments the same way the Redis metrics store does', function () {
    config()->set('queue-metrics.storage.prefix', 'queue_metrics');

    $builderKey = (new MetricsKeyBuilder)->key('jobs', 'redis', 'reports:nightly', 'App\\Jobs\\A~B');

    expect($builderKey)->toBe('queue_metrics:jobs:redis:reports~cnightly:App\\Jobs\\A~~B')
        ->and($builderKey)->toBe((new RedisMetricsStore)->key('jobs', 'redis', 'reports:nightly', 'App\\Jobs\\A~B'));
});
