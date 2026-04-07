<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Utilities;

/**
 * Parse PHP memory_limit into megabytes.
 */
final class MemoryLimitParser
{
    /**
     * Get the current PHP memory_limit in megabytes.
     *
     * Returns null if memory is unlimited (-1) or empty.
     */
    public static function getCurrentLimitMb(): ?float
    {
        $limit = ini_get('memory_limit');
        if ($limit === '' || $limit === '-1') {
            return null;
        }

        return self::parseToMb($limit);
    }

    /**
     * Parse a PHP memory shorthand value (e.g. "128M", "1G") to megabytes.
     */
    public static function parseToMb(string $value): float
    {
        $numericValue = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $numericValue * 1024.0,
            'M' => (float) $numericValue,
            'K' => $numericValue / 1024.0,
            default => $numericValue / 1024.0 / 1024.0,
        };
    }
}
