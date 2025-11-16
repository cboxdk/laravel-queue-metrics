<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Memory usage statistics in megabytes.
 */
final readonly class MemoryStats
{
    public function __construct(
        public float $avg,
        public float $peak,
        public float $p95,
        public float $p99,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            avg: is_numeric($data['avg'] ?? 0.0) ? (float) $data['avg'] : 0.0,
            peak: is_numeric($data['peak'] ?? 0.0) ? (float) $data['peak'] : 0.0,
            p95: is_numeric($data['p95'] ?? 0.0) ? (float) $data['p95'] : 0.0,
            p99: is_numeric($data['p99'] ?? 0.0) ? (float) $data['p99'] : 0.0,
        );
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'avg' => $this->avg,
            'peak' => $this->peak,
            'p95' => $this->p95,
            'p99' => $this->p99,
        ];
    }
}
