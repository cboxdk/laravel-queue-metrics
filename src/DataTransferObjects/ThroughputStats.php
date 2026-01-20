<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

/**
 * Throughput statistics (jobs per time period).
 */
final readonly class ThroughputStats
{
    public function __construct(
        public float $perMinute,
        public float $perHour,
        public float $perDay,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $perMinute = $data['per_minute'] ?? 0.0;
        $perHour = $data['per_hour'] ?? 0.0;
        $perDay = $data['per_day'] ?? 0.0;

        return new self(
            perMinute: is_numeric($perMinute) ? (float) $perMinute : 0.0,
            perHour: is_numeric($perHour) ? (float) $perHour : 0.0,
            perDay: is_numeric($perDay) ? (float) $perDay : 0.0,
        );
    }

    /**
     * @return array<string, float>
     */
    public function toArray(): array
    {
        return [
            'per_minute' => $this->perMinute,
            'per_hour' => $this->perHour,
            'per_day' => $this->perDay,
        ];
    }
}
