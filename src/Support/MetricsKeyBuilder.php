<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Helper for building Redis keys with consistent prefixing.
 */
final readonly class MetricsKeyBuilder
{
    private string $prefix;

    public function __construct(?string $prefix = null)
    {
        /** @var string $configPrefix */
        $configPrefix = config('queue-metrics.storage.prefix', 'queue_metrics');
        $this->prefix = $prefix ?? $configPrefix;
    }

    /**
     * Build a Redis key from segments.
     */
    public function key(string ...$segments): string
    {
        return $this->prefix.':'.implode(':', $segments);
    }

    /**
     * Get TTL for a given key type.
     */
    public function getTtl(string $type): int
    {
        /** @var int */
        return config("queue-metrics.storage.ttl.{$type}", 3600);
    }
}
