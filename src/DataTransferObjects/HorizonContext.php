<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\DataTransferObjects;

/**
 * Immutable DTO containing Horizon-specific worker context.
 */
final readonly class HorizonContext
{
    public function __construct(
        public bool $isHorizon,
        public ?string $supervisorName = null,
        public ?int $parentId = null,
        public ?string $workersName = null,
    ) {}

    public static function notHorizon(): self
    {
        return new self(isHorizon: false);
    }

    public static function fromDetection(
        string $supervisorName,
        ?int $parentId = null,
        ?string $workersName = null,
    ): self {
        return new self(
            isHorizon: true,
            supervisorName: $supervisorName,
            parentId: $parentId,
            workersName: $workersName,
        );
    }
}
