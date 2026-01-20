<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Exceptions;

use RuntimeException;

/**
 * Exception thrown when configuration validation fails.
 */
final class ConfigurationException extends RuntimeException
{
    public static function invalidConnection(string $connection): self
    {
        return new self("Invalid queue-metrics.storage.connection configuration: '{$connection}'. Must be a non-empty string.");
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self("Invalid queue-metrics.storage.prefix configuration: '{$prefix}'. Must be a non-empty string.");
    }

    public static function invalidSlidingWindow(int|float $value): self
    {
        return new self("Invalid queue-metrics.baseline.sliding_window_days configuration: '{$value}'. Must be at least 1 day.");
    }

    public static function invalidDecayFactor(float $value): self
    {
        return new self("Invalid queue-metrics.baseline.decay_factor configuration: '{$value}'. Must be between 0 and 1.");
    }

    public static function invalidStaleThreshold(int $value): self
    {
        return new self("Invalid queue-metrics.worker_heartbeat.stale_threshold configuration: '{$value}'. Must be at least 1 second.");
    }
}
