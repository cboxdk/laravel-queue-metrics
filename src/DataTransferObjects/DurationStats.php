<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $avg = $data['avg'] ?? 0.0;
        $min = $data['min'] ?? 0.0;
        $max = $data['max'] ?? 0.0;
        $p50 = $data['p50'] ?? 0.0;
        $p95 = $data['p95'] ?? 0.0;
        $p99 = $data['p99'] ?? 0.0;
        $stddev = $data['stddev'] ?? 0.0;

        return new self(
            avg: is_numeric($avg) ? (float) $avg : 0.0,
            min: is_numeric($min) ? (float) $min : 0.0,
            max: is_numeric($max) ? (float) $max : 0.0,
            p50: is_numeric($p50) ? (float) $p50 : 0.0,
            p95: is_numeric($p95) ? (float) $p95 : 0.0,
            p99: is_numeric($p99) ? (float) $p99 : 0.0,
            stddev: is_numeric($stddev) ? (float) $stddev : 0.0,
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
