<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $count = $data['count'] ?? 0;
        $rate = $data['rate'] ?? 0.0;
        $lastFailedAt = $data['last_failed_at'] ?? null;
        $lastException = $data['last_exception'] ?? null;

        return new self(
            count: is_numeric($count) ? (int) $count : 0,
            rate: is_numeric($rate) ? (float) $rate : 0.0,
            lastFailedAt: (is_string($lastFailedAt) || $lastFailedAt instanceof \DateTimeInterface)
                ? Carbon::parse($lastFailedAt)
                : null,
            lastException: is_string($lastException) ? $lastException : null,
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
