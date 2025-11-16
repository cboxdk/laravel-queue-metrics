<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

use Carbon\Carbon;

/**
 * Job failure information.
 */
final readonly class FailureInfo
{
    public function __construct(
        public int $count,
        public float $rate,
        public ?Carbon $lastFailedAt,
        public ?string $lastException,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            count: (int) ($data['count'] ?? 0),
            rate: (float) ($data['rate'] ?? 0.0),
            lastFailedAt: isset($data['last_failed_at']) ? Carbon::parse($data['last_failed_at']) : null,
            lastException: $data['last_exception'] ?? null,
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'rate' => $this->rate,
            'last_failed_at' => $this->lastFailedAt?->toIso8601String(),
            'last_exception' => $this->lastException,
        ];
    }
}
