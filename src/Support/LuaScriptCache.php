<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Static cache for Lua script SHA1 hashes to avoid reloading scripts.
 * Separated from readonly classes to allow mutable static state.
 */
final class LuaScriptCache
{
    /**
     * Cache of script file paths to their SHA1 hashes.
     *
     * @var array<string, string>
     */
    private static array $scriptShas = [];

    /**
     * Private constructor to prevent instantiation.
     */
    private function __construct() {}

    /**
     * Get cached SHA for a script file, or null if not cached.
     */
    public static function get(string $scriptPath): ?string
    {
        return self::$scriptShas[$scriptPath] ?? null;
    }

    /**
     * Set SHA for a script file.
     */
    public static function set(string $scriptPath, string $sha): void
    {
        self::$scriptShas[$scriptPath] = $sha;
    }

    /**
     * Clear all cached SHAs (useful for testing).
     */
    public static function clear(): void
    {
        self::$scriptShas = [];
    }
}
