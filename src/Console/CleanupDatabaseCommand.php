<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Models\MetricsKey;
use Cbox\LaravelQueueMetrics\Models\MetricsSortedSet;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CleanupDatabaseCommand extends Command
{
    public $signature = 'queue-metrics:cleanup-database';

    public $description = 'Remove expired metrics data from database storage';

    public function handle(): int
    {
        $chunkSize = is_numeric($configChunk = config('queue-metrics.storage.cleanup_chunk_size', 1000)) ? (int) $configChunk : 1000;

        // Delete expired rows via subquery to support SQLite (no auto-increment id on all tables)
        MetricsKey::whereIn('key', MetricsKey::expired()->limit($chunkSize)->pluck('key'))->delete();
        MetricsHash::whereIn('key', MetricsHash::expired()->limit($chunkSize)->pluck('key'))->delete();

        // MetricsSortedSet has a composite unique key (key, member) — delete via member
        /** @var Collection<string, Collection<int, MetricsSortedSet>> $grouped */
        $grouped = MetricsSortedSet::expired()
            ->orderBy('key')
            ->orderBy('member')
            ->limit($chunkSize)
            ->get()
            ->groupBy('key');

        $grouped->each(function ($rows, $key) {
            MetricsSortedSet::where('key', (string) $key)
                ->whereIn('member', $rows->pluck('member'))
                ->delete();
        });

        // Trim sorted sets exceeding max_samples_per_key
        $maxSamples = is_numeric($configMax = config('queue-metrics.storage.max_samples_per_key', 1000)) ? (int) $configMax : 1000;

        $oversized = MetricsSortedSet::query()
            ->select('key')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > ?', [$maxSamples])
            ->get();

        foreach ($oversized as $row) {
            $rowKey = $row->key;
            /** @var int $rowCnt */
            $rowCnt = $row->getAttribute('cnt');

            $excess = MetricsSortedSet::where('key', $rowKey)
                ->orderBy('score')
                ->limit($rowCnt - $maxSamples)
                ->pluck('member');

            MetricsSortedSet::where('key', $rowKey)
                ->whereIn('member', $excess)
                ->delete();
        }

        return self::SUCCESS;
    }
}
