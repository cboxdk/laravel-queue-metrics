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
            avg: (float) ($data['avg'] ?? 0.0),
            peak: (float) ($data['peak'] ?? 0.0),
            p95: (float) ($data['p95'] ?? 0.0),
            p99: (float) ($data['p99'] ?? 0.0),
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
