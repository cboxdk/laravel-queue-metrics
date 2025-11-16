<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Duration statistics in milliseconds.
 */
final readonly class DurationStats
{
    public function __construct(
        public float $avg,
        public float $min,
        public float $max,
        public float $p50,
        public float $p95,
        public float $p99,
        public float $stddev,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            avg: (float) ($data['avg'] ?? 0.0),
            min: (float) ($data['min'] ?? 0.0),
            max: (float) ($data['max'] ?? 0.0),
            p50: (float) ($data['p50'] ?? 0.0),
            p95: (float) ($data['p95'] ?? 0.0),
            p99: (float) ($data['p99'] ?? 0.0),
            stddev: (float) ($data['stddev'] ?? 0.0),
        );
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'avg' => $this->avg,
            'min' => $this->min,
            'max' => $this->max,
            'p50' => $this->p50,
            'p95' => $this->p95,
            'p99' => $this->p99,
            'stddev' => $this->stddev,
        ];
    }
}
