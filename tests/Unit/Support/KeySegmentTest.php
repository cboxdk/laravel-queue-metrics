<?php

declare(strict_types=1);

use Cbox\LaravelQueueMetrics\Support\KeySegment;

it('leaves segments without a colon or tilde unchanged', function (string $segment) {
    expect(KeySegment::escape($segment))->toBe($segment);
})->with(['default', 'App\\Jobs\\SendInvoice', 'tenant-42_emails.fifo', '*', '']);

it('round-trips any segment', function (string $segment) {
    expect(KeySegment::unescape(KeySegment::escape($segment)))->toBe($segment);
})->with(['reports:nightly', 'a~b', '~c', 'a~:b', '::', '~~', 'Closure (routes/console.php:12)']);

it('never leaves the key separator inside an escaped segment', function () {
    expect(KeySegment::escape('reports:nightly'))->not->toContain(':')
        ->and(KeySegment::join('redis', 'reports:nightly', 'App\\Jobs\\A'))->toBe('redis:reports~cnightly:App\\Jobs\\A');
});
