<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when queue health score changes significantly.
 * Real-time health monitoring for UI and alerting systems.
 */
final class HealthScoreChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly float $currentScore,
        public readonly float $previousScore,
        public readonly string $status,
    ) {}

    public function getSeverity(): string
    {
        $change = abs($this->currentScore - $this->previousScore);

        return match (true) {
            $change >= 30 => 'critical',
            $change >= 20 => 'warning',
            $change >= 10 => 'info',
            default => 'normal',
        };
    }
}
