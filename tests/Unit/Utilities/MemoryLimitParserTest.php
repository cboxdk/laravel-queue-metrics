<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Utilities\MemoryLimitParser;

it('parses megabyte values', function () {
    expect(MemoryLimitParser::parseToMb('128M'))->toBe(128.0)
        ->and(MemoryLimitParser::parseToMb('256M'))->toBe(256.0)
        ->and(MemoryLimitParser::parseToMb('512M'))->toBe(512.0);
});

it('parses gigabyte values', function () {
    expect(MemoryLimitParser::parseToMb('1G'))->toBe(1024.0)
        ->and(MemoryLimitParser::parseToMb('2G'))->toBe(2048.0);
});

it('parses kilobyte values', function () {
    expect(MemoryLimitParser::parseToMb('1024K'))->toBe(1.0)
        ->and(MemoryLimitParser::parseToMb('512K'))->toBe(0.5);
});

it('parses raw byte values', function () {
    // 128 MB in bytes
    expect(MemoryLimitParser::parseToMb('134217728'))->toBe(128.0);
});

it('handles lowercase suffixes', function () {
    expect(MemoryLimitParser::parseToMb('128m'))->toBe(128.0)
        ->and(MemoryLimitParser::parseToMb('1g'))->toBe(1024.0)
        ->and(MemoryLimitParser::parseToMb('1024k'))->toBe(1.0);
});

it('returns current memory limit in megabytes', function () {
    $originalLimit = ini_get('memory_limit');

    ini_set('memory_limit', '256M');
    expect(MemoryLimitParser::getCurrentLimitMb())->toBe(256.0);

    ini_set('memory_limit', '1G');
    expect(MemoryLimitParser::getCurrentLimitMb())->toBe(1024.0);

    ini_set('memory_limit', $originalLimit);
});

it('returns null for unlimited memory', function () {
    $originalLimit = ini_get('memory_limit');

    ini_set('memory_limit', '-1');
    expect(MemoryLimitParser::getCurrentLimitMb())->toBeNull();

    ini_set('memory_limit', $originalLimit);
});
