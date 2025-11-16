<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Statistics for a specific time window.
 */
final readonly class WindowStats
{
    public function __construct(
        public int $windowSeconds,
        public int $jobsProcessed,
        public float $avgDuration,
        public float $throughput,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            windowSeconds: is_numeric($data['window_seconds'] ?? 0) ? (int) $data['window_seconds'] : 0,
            jobsProcessed: is_numeric($data['jobs_processed'] ?? 0) ? (int) $data['jobs_processed'] : 0,
            avgDuration: is_numeric($data['avg_duration'] ?? 0.0) ? (float) $data['avg_duration'] : 0.0,
            throughput: is_numeric($data['throughput'] ?? 0.0) ? (float) $data['throughput'] : 0.0,
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'window_seconds' => $this->windowSeconds,
            'jobs_processed' => $this->jobsProcessed,
            'avg_duration' => $this->avgDuration,
            'throughput' => $this->throughput,
        ];
    }

    public function getWindowLabel(): string
    {
        return match (true) {
            $this->windowSeconds < 60 => "{$this->windowSeconds}s",
            $this->windowSeconds < 3600 => (int) ($this->windowSeconds / 60) . 'm',
            $this->windowSeconds < 86400 => (int) ($this->windowSeconds / 3600) . 'h',
            default => (int) ($this->windowSeconds / 86400) . 'd',
        };
    }
}
