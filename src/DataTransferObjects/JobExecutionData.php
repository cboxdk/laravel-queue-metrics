<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Job execution statistics.
 */
final readonly class JobExecutionData
{
    public function __construct(
        public int $totalProcessed,
        public int $totalFailed,
        public float $successRate,
        public float $failureRate,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $totalProcessed = (int) ($data['total_processed'] ?? 0);
        $totalFailed = (int) ($data['total_failed'] ?? 0);
        $total = $totalProcessed + $totalFailed;

        return new self(
            totalProcessed: $totalProcessed,
            totalFailed: $totalFailed,
            successRate: $total > 0 ? ($totalProcessed / $total) * 100 : 0.0,
            failureRate: $total > 0 ? ($totalFailed / $total) * 100 : 0.0,
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'total_processed' => $this->totalProcessed,
            'total_failed' => $this->totalFailed,
            'success_rate' => $this->successRate,
            'failure_rate' => $this->failureRate,
        ];
    }
}
