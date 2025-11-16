<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPeek\LaravelQueueMetrics\Config\StorageConfig;
use PHPeek\LaravelQueueMetrics\Storage\Contracts\StorageDriver;

/**
 * Manages storage driver instantiation and configuration.
 */
final readonly class StorageManager
{
    public function __construct(
        private StorageConfig $config,
    ) {}

    public function driver(): StorageDriver
    {
        return match ($this->config->driver) {
            'redis' => $this->createRedisDriver(),
            'database' => $this->createDatabaseDriver(),
            default => throw new \InvalidArgumentException("Unsupported storage driver: {$this->config->driver}"),
        };
    }

    public function getPrefix(): string
    {
        return $this->config->prefix;
    }

    public function getTtl(string $type): int
    {
        return $this->config->getTtl($type);
    }

    public function key(string ...$parts): string
    {
        return $this->config->prefix . ':' . implode(':', $parts);
    }

    private function createRedisDriver(): RedisStorageDriver
    {
        /** @var \Illuminate\Redis\Connections\Connection */
        $connection = Redis::connection($this->config->connection);

        return new RedisStorageDriver($connection);
    }

    private function createDatabaseDriver(): DatabaseStorageDriver
    {
        $connection = DB::connection($this->config->connection);

        return new DatabaseStorageDriver($connection, $this->config->prefix . '_');
    }
}
