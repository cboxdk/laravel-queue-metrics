<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

/**
 * Queue health statistics.
 */
final readonly class HealthStats
{
    public function __construct(
        public string $status,
        public float $score,
        public int $depth,
        public int $oldestJobAge,
        public float $failureRate,
        public float $utilizationRate,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? 'unknown';
        $score = $data['score'] ?? 0.0;
        $depth = $data['depth'] ?? 0;
        $oldestJobAge = $data['oldest_job_age'] ?? 0;
        $failureRate = $data['failure_rate'] ?? 0.0;
        $utilizationRate = $data['utilization_rate'] ?? 0.0;

        return new self(
            status: is_string($status) ? $status : 'unknown',
            score: is_numeric($score) ? (float) $score : 0.0,
            depth: is_numeric($depth) ? (int) $depth : 0,
            oldestJobAge: is_numeric($oldestJobAge) ? (int) $oldestJobAge : 0,
            failureRate: is_numeric($failureRate) ? (float) $failureRate : 0.0,
            utilizationRate: is_numeric($utilizationRate) ? (float) $utilizationRate : 0.0,
        );
    }

    /**
     * @return array<string, string|int|float>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'score' => $this->score,
            'depth' => $this->depth,
            'oldest_job_age' => $this->oldestJobAge,
            'failure_rate' => $this->failureRate,
            'utilization_rate' => $this->utilizationRate,
        ];
    }

    public function isHealthy(): bool
    {
        return $this->status === 'healthy' || $this->score >= 80.0;
    }

    public function isWarning(): bool
    {
        return $this->status === 'warning' || ($this->score >= 50.0 && $this->score < 80.0);
    }

    public function isCritical(): bool
    {
        return $this->status === 'critical' || $this->score < 50.0;
    }
}
