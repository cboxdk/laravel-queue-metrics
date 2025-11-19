<?php

declare(strict_types=1);

use PHPeek\LaravelQueueMetrics\Config\StorageConfig;

it('creates config from array with all values', function () {
    $config = StorageConfig::fromArray([
        'driver' => 'redis',
        'connection' => 'metrics',
        'prefix' => 'custom_prefix',
        'ttl' => [
            'raw' => 1800,
            'aggregated' => 86400,
            'baseline' => 604800,
        ],
    ]);

    expect($config->driver)->toBe('redis')
        ->and($config->connection)->toBe('metrics')
        ->and($config->prefix)->toBe('custom_prefix')
        ->and($config->ttls)->toBe([
            'raw' => 1800,
            'aggregated' => 86400,
            'baseline' => 604800,
        ]);
})->group('functional');

it('creates config with defaults when values missing', function () {
    $config = StorageConfig::fromArray([]);

    expect($config->driver)->toBe('redis')
        ->and($config->connection)->toBe('default')
        ->and($config->prefix)->toBe('queue_metrics')
        ->and($config->ttls)->toBe([
            'raw' => 3600,
            'aggregated' => 604800,
            'baseline' => 2592000,
        ]);
})->group('functional');

it('gets ttl for known type', function () {
    $config = StorageConfig::fromArray([
        'ttl' => [
            'raw' => 1800,
            'aggregated' => 86400,
            'baseline' => 604800,
        ],
    ]);

    expect($config->getTtl('raw'))->toBe(1800)
        ->and($config->getTtl('aggregated'))->toBe(86400)
        ->and($config->getTtl('baseline'))->toBe(604800);
})->group('functional');

it('returns default ttl for unknown type', function () {
    $config = StorageConfig::fromArray([
        'ttl' => [
            'raw' => 1800,
        ],
    ]);

    expect($config->getTtl('unknown'))->toBe(3600);
})->group('functional');

it('handles database driver', function () {
    $config = StorageConfig::fromArray([
        'driver' => 'database',
        'connection' => 'mysql',
    ]);

    expect($config->driver)->toBe('database')
        ->and($config->connection)->toBe('mysql');
})->group('functional');

it('is readonly', function () {
    $config = StorageConfig::fromArray([]);

    expect(fn () => $config->driver = 'changed')
        ->toThrow(Error::class);
})->group('functional');
