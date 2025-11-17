<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Utilities;

use PHPeek\LaravelQueueMetrics\DataTransferObjects\HorizonContext;

/**
 * Detects if current process is running under Laravel Horizon.
 * Extracts supervisor name and other Horizon-specific metadata.
 */
final class HorizonDetector
{
    /**
     * Detect Horizon context from current process.
     *
     * Checks for horizon:work command and extracts supervisor metadata
     * from command line arguments.
     */
    public static function detect(): HorizonContext
    {
        $argv = $_SERVER['argv'] ?? [];

        // Check if running horizon:work command
        if (! self::isHorizonWork($argv)) {
            return HorizonContext::notHorizon();
        }

        // Extract Horizon arguments
        $supervisorName = self::extractArgument($argv, '--supervisor-name');
        $parentId = self::extractArgument($argv, '--parent-id');
        $workersName = self::extractArgument($argv, '--workers-name');

        // Supervisor name is required for Horizon
        if ($supervisorName === null) {
            return HorizonContext::notHorizon();
        }

        return HorizonContext::fromDetection(
            supervisorName: $supervisorName,
            parentId: $parentId !== null ? (int) $parentId : null,
            workersName: $workersName,
        );
    }

    /**
     * Check if horizon:work command is in argv.
     */
    private static function isHorizonWork(array $argv): bool
    {
        foreach ($argv as $arg) {
            if (str_contains($arg, 'horizon:work')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract argument value from argv.
     * Supports both --arg=value and --arg value formats.
     */
    private static function extractArgument(array $argv, string $name): ?string
    {
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];

            // Format: --arg=value
            if (str_starts_with($arg, $name . '=')) {
                return substr($arg, strlen($name) + 1);
            }

            // Format: --arg value
            if ($arg === $name && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
        }

        return null;
    }

    /**
     * Generate worker ID with Horizon context.
     *
     * Returns format:
     * - Horizon: worker_horizon_supervisor-1_hostname_pid
     * - Standard: worker_hostname_pid
     */
    public static function generateWorkerId(?HorizonContext $context = null): string
    {
        $context ??= self::detect();

        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid();

        if ($context->isHorizon && $context->supervisorName !== null) {
            return sprintf(
                'worker_horizon_%s_%s_%d',
                $context->supervisorName,
                $hostname,
                $pid
            );
        }

        return sprintf('worker_%s_%d', $hostname, $pid);
    }
}
