<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Models\MetricsKey;
use Cbox\LaravelQueueMetrics\Models\MetricsSortedSet;

beforeEach(function () {
    config()->set('queue-metrics.storage.connection', null);

    $migration = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $migration->up();
});

test('cleanup removes expired keys', function () {
    MetricsKey::create(['key' => 'old', 'value' => 'x', 'expires_at' => now()->subHour(), 'updated_at' => now()]);
    MetricsKey::create(['key' => 'fresh', 'value' => 'x', 'expires_at' => now()->addHour(), 'updated_at' => now()]);

    $this->artisan('queue-metrics:cleanup-database')->assertExitCode(0);

    expect(MetricsKey::count())->toBe(1);
    expect(MetricsKey::first()->key)->toBe('fresh');
});

test('cleanup removes expired hashes', function () {
    MetricsHash::create(['key' => 'old', 'data' => [], 'expires_at' => now()->subHour(), 'updated_at' => now()]);

    $this->artisan('queue-metrics:cleanup-database')->assertExitCode(0);

    expect(MetricsHash::count())->toBe(0);
});

test('cleanup trims sorted sets exceeding max samples', function () {
    $max = config('queue-metrics.storage.max_samples_per_key', 1000);

    for ($i = 0; $i < $max + 50; $i++) {
        MetricsSortedSet::create([
            'key' => 'samples:test',
            'member' => "m-{$i}",
            'score' => $i,
            'updated_at' => now(),
        ]);
    }

    $this->artisan('queue-metrics:cleanup-database')->assertExitCode(0);

    expect(MetricsSortedSet::where('key', 'samples:test')->count())->toBe($max);
});

test('cleanup does nothing when no expired data', function () {
    MetricsKey::create(['key' => 'valid', 'value' => 'x', 'expires_at' => now()->addDay(), 'updated_at' => now()]);

    $this->artisan('queue-metrics:cleanup-database')->assertExitCode(0);

    expect(MetricsKey::count())->toBe(1);
});
