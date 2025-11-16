<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Enums;

/**
 * Worker state enum.
 */
enum WorkerState: string
{
    case IDLE = 'idle';
    case BUSY = 'busy';
    case PAUSED = 'paused';
    case STOPPED = 'stopped';
    case CRASHED = 'crashed';
    case UNKNOWN = 'unknown';

    public function isHealthy(): bool
    {
        return in_array($this, [self::IDLE, self::BUSY, self::PAUSED], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::IDLE, self::BUSY], true);
    }

    public function requiresAttention(): bool
    {
        return in_array($this, [self::CRASHED, self::STOPPED], true);
    }
}
