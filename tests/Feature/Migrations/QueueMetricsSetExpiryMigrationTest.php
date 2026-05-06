<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('set expiry migration rolls back cleanly after a fresh install', function () {
    $createTables = include __DIR__.'/../../../database/migrations/2024_01_01_000001_create_queue_metrics_storage_tables.php';
    $addSetExpiry = include __DIR__.'/../../../database/migrations/2024_01_01_000002_add_expires_at_to_queue_metrics_sets.php';

    $createTables->up();
    expect(Schema::hasColumn('queue_metrics_sets', 'expires_at'))->toBeFalse();

    $addSetExpiry->up();
    expect(Schema::hasColumn('queue_metrics_sets', 'expires_at'))->toBeTrue();

    $addSetExpiry->down();
    expect(Schema::hasColumn('queue_metrics_sets', 'expires_at'))->toBeFalse();
});
