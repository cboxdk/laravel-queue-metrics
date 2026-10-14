<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Support;

/**
 * Escapes one segment of a storage key so it can never contain the `:` that
 * separates segments.
 *
 * Queue names may contain colons (`reports:nightly`), and parsing a key by
 * position would otherwise cut such a name at its first colon. `~` is the
 * escape character because it has no meaning in Redis SCAN globs or SQL LIKE
 * patterns, and segments with neither `:` nor `~` are left unchanged, so the
 * keys of ordinary names stay the same.
 *
 * @internal
 */
final class KeySegment
{
    public static function escape(string $segment): string
    {
        return strtr($segment, ['~' => '~~', ':' => '~c']);
    }

    public static function unescape(string $segment): string
    {
        return strtr($segment, ['~~' => '~', '~c' => ':']);
    }

    public static function join(string ...$segments): string
    {
        return implode(':', array_map(self::escape(...), $segments));
    }
}
