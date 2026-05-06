<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Utilities;

/**
 * @internal
 */
final class MemoryConverter
{
    public static function bytesToMegabytes(float|int $bytes): float
    {
        return $bytes / 1024 / 1024;
    }
}
