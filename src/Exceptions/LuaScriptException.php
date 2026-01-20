<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Exceptions;

use RuntimeException;

/**
 * Exception thrown when Lua script operations fail.
 */
final class LuaScriptException extends RuntimeException
{
    public static function failedToLoad(string $scriptPath): self
    {
        return new self("Failed to load Lua script from {$scriptPath}");
    }

    public static function invalidSha(string $scriptPath): self
    {
        return new self("SCRIPT LOAD did not return a valid SHA1 string for {$scriptPath}");
    }
}
