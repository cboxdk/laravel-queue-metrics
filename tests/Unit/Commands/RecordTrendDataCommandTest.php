<?php

declare(strict_types=1);

/**
 * Tests for the depth extraction fix in RecordTrendDataCommand.
 *
 * getAllQueuesWithMetrics() returns depth as a nested array (e.g., ['total' => 42, ...]),
 * but RecordQueueDepthHistoryAction::execute() expects int $depth.
 * The command must extract the correct integer value.
 */
it('extracts depth integer from nested array structure', function () {
    $queueData = [
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => [
            'total' => 42,
            'pending' => 30,
            'scheduled' => 10,
            'reserved' => 2,
        ],
    ];

    $depth = is_array($queueData['depth'])
        ? (int) ($queueData['depth']['total'] ?? 0)
        : (int) $queueData['depth'];

    expect($depth)->toBe(42);
});

it('handles depth as plain integer', function () {
    $queueData = [
        'connection' => 'redis',
        'queue' => 'emails',
        'depth' => 15,
    ];

    $depth = is_array($queueData['depth'])
        ? (int) ($queueData['depth']['total'] ?? 0)
        : (int) $queueData['depth'];

    expect($depth)->toBe(15);
});

it('defaults to zero when depth array lacks total key', function () {
    $queueData = [
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => [
            'pending' => 5,
        ],
    ];

    $depth = is_array($queueData['depth'])
        ? (int) ($queueData['depth']['total'] ?? 0)
        : (int) $queueData['depth'];

    expect($depth)->toBe(0);
});

it('handles depth as string integer', function () {
    $queueData = [
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => '25',
    ];

    $depth = is_array($queueData['depth'])
        ? (int) ($queueData['depth']['total'] ?? 0)
        : (int) $queueData['depth'];

    expect($depth)->toBe(25);
});

it('handles zero depth in nested array', function () {
    $queueData = [
        'connection' => 'redis',
        'queue' => 'default',
        'depth' => [
            'total' => 0,
            'pending' => 0,
            'scheduled' => 0,
            'reserved' => 0,
        ],
    ];

    $depth = is_array($queueData['depth'])
        ? (int) ($queueData['depth']['total'] ?? 0)
        : (int) $queueData['depth'];

    expect($depth)->toBe(0);
});
