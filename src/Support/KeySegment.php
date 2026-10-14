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

    /**
     * Split a value written by join() back into its segments.
     *
     * Returns null unless the value has exactly $segmentCount segments and is
     * byte-for-byte what join() writes for them. A value stored before
     * segments were escaped (`redis:reports:nightly:App\Jobs\Report`) fails
     * that check rather than being read back with the wrong boundaries.
     *
     * @return list<string>|null
     */
    public static function split(string $joined, int $segmentCount): ?array
    {
        $segments = array_map(self::unescape(...), explode(':', $joined));

        if (count($segments) !== $segmentCount || self::join(...$segments) !== $joined) {
            return null;
        }

        return $segments;
    }
}
