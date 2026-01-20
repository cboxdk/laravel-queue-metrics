<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Config;

/**
 * Storage configuration with type-safe accessors.
 */
final readonly class StorageConfig
{
    /**
     * @param  array<string, int>  $ttls
     */
    public function __construct(
        public string $driver,
        public string $connection,
        public string $prefix,
        public array $ttls,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, int> */
        $ttls = is_array($config['ttl'] ?? null) ? $config['ttl'] : [
            'raw' => 3600,
            'aggregated' => 604800,
            'baseline' => 2592000,
        ];

        return new self(
            driver: is_string($config['driver'] ?? null) ? $config['driver'] : 'redis',
            connection: is_string($config['connection'] ?? null) ? $config['connection'] : 'default',
            prefix: is_string($config['prefix'] ?? null) ? $config['prefix'] : 'queue_metrics',
            ttls: $ttls,
        );
    }

    public function getTtl(string $type): int
    {
        return $this->ttls[$type] ?? 3600;
    }
}
