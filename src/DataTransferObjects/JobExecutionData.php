<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\DataTransferObjects;

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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $totalProcessedValue = $data['total_processed'] ?? 0;
        $totalFailedValue = $data['total_failed'] ?? 0;

        $totalProcessed = is_numeric($totalProcessedValue) ? (int) $totalProcessedValue : 0;
        $totalFailed = is_numeric($totalFailedValue) ? (int) $totalFailedValue : 0;
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
