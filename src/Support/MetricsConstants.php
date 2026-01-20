<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Central constants for queue metrics system.
 *
 * Provides consistent values across the package for time intervals,
 * limits, thresholds, and performance tuning parameters.
 */
final class MetricsConstants
{
    // Time Intervals (in seconds)
    public const SECONDS_PER_MINUTE = 60;

    public const SECONDS_PER_HOUR = 3600;

    public const SECONDS_PER_DAY = 86400;

    public const SECONDS_PER_5_MINUTES = 300;

    // Default TTL Values
    public const DEFAULT_TTL_SECONDS = self::SECONDS_PER_HOUR;

    // Worker Heartbeat
    public const DEFAULT_STALE_WORKER_THRESHOLD = self::SECONDS_PER_MINUTE;

    public const DEFAULT_HEARTBEAT_INTERVAL = 30;

    // Baseline & Sampling
    public const DEFAULT_BASELINE_SAMPLES = 100;

    public const DEFAULT_RECENT_SAMPLES = 10;

    // Data Limits
    public const MAX_EXCEPTION_LENGTH = 1000;

    public const MAX_QUEUE_SNAPSHOTS = 1000;

    public const REDIS_SCAN_COUNT = 1000;

    // Health Score Thresholds
    public const HEALTH_SCORE_DEPTH_THRESHOLD = 100;

    public const HEALTH_SCORE_AGE_THRESHOLD = self::SECONDS_PER_5_MINUTES;

    public const HEALTH_SCORE_MAX_PENALTY = 30;

    public const HEALTH_SCORE_DEPTH_DIVISOR = 10;

    public const HEALTH_SCORE_AGE_DIVISOR = 60;

    // API Default Query Parameters
    public const DEFAULT_TREND_PERIOD = self::SECONDS_PER_HOUR;

    public const DEFAULT_TREND_INTERVAL = self::SECONDS_PER_MINUTE;

    public const MAX_TREND_PERIOD = self::SECONDS_PER_DAY * 7; // 1 week

    public const MAX_TREND_INTERVAL = self::SECONDS_PER_HOUR * 4; // 4 hours

    public const MIN_TREND_INTERVAL = 10; // 10 seconds

    // Percentile Calculation
    public const PERCENTILE_DIVISOR = 100.0;

    // Performance tuning (moved from config)
    public const BATCH_SIZE = 100;

    public const PERCENTILE_SAMPLES = 1000;

    public const BASELINE_SAMPLES = 100;

    // Time windows (moved from config)
    public const WINDOWS_SHORT = [60, 300, 900]; // 1 min, 5 min, 15 min

    public const WINDOWS_MEDIUM = [3600]; // 1 hour

    public const WINDOWS_LONG = [86400]; // 1 day

    // Event thresholds (moved from config)
    public const QUEUE_DEPTH_THRESHOLD = 100;

    public const EFFICIENCY_CHANGE_THRESHOLD = 10; // percent

    public const HEALTH_SCORE_CHANGE_THRESHOLD = 15; // points

    /**
     * Get time interval in seconds.
     */
    public static function timeInterval(string $unit): int
    {
        return match ($unit) {
            'minute' => self::SECONDS_PER_MINUTE,
            'hour' => self::SECONDS_PER_HOUR,
            'day' => self::SECONDS_PER_DAY,
            '5minutes' => self::SECONDS_PER_5_MINUTES,
            default => throw new \InvalidArgumentException("Unknown time unit: {$unit}"),
        };
    }

    /**
     * Check if a value is within valid range.
     */
    public static function isValidTrendPeriod(int $period): bool
    {
        return $period > 0 && $period <= self::MAX_TREND_PERIOD;
    }

    /**
     * Check if interval is within valid range.
     */
    public static function isValidTrendInterval(int $interval): bool
    {
        return $interval >= self::MIN_TREND_INTERVAL && $interval <= self::MAX_TREND_INTERVAL;
    }

    /**
     * Clamp a value within min/max bounds.
     */
    public static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
