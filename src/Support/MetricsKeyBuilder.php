<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Support;

/**
 * Helper for building Redis keys with consistent prefixing.
 */
final readonly class MetricsKeyBuilder
{
    private string $prefix;

    public function __construct(?string $prefix = null)
    {
        $this->prefix = $prefix ?? config('queue-metrics.storage.prefix', 'queue_metrics');
    }

    /**
     * Build a Redis key from segments.
     */
    public function key(string ...$segments): string
    {
        return $this->prefix . ':' . implode(':', $segments);
    }

    /**
     * Get TTL for a given key type.
     */
    public function getTtl(string $type): int
    {
        return config("queue-metrics.storage.ttl.{$type}", 3600);
    }
}
