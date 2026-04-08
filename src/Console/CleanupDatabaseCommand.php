<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Console;

use Cbox\LaravelQueueMetrics\Models\MetricsHash;
use Cbox\LaravelQueueMetrics\Models\MetricsKey;
use Cbox\LaravelQueueMetrics\Models\MetricsSortedSet;
use Illuminate\Console\Command;

class CleanupDatabaseCommand extends Command
{
    public $signature = 'queue-metrics:cleanup-database';

    public $description = 'Remove expired metrics data from database storage';

    public function handle(): int
    {
        $chunkSize = (int) config('queue-metrics.storage.cleanup_chunk_size', 1000);

        // Delete expired rows via subquery to support SQLite (no auto-increment id on all tables)
        MetricsKey::whereIn('key', MetricsKey::expired()->limit($chunkSize)->pluck('key'))->delete();
        MetricsHash::whereIn('key', MetricsHash::expired()->limit($chunkSize)->pluck('key'))->delete();

        // MetricsSortedSet has a composite unique key (key, member) — delete via member
        MetricsSortedSet::expired()
            ->orderBy('key')
            ->orderBy('member')
            ->limit($chunkSize)
            ->get(['key', 'member'])
            ->groupBy('key')
            ->each(function ($rows, $key) {
                MetricsSortedSet::where('key', $key)
                    ->whereIn('member', $rows->pluck('member'))
                    ->delete();
            });

        // Trim sorted sets exceeding max_samples_per_key
        $maxSamples = (int) config('queue-metrics.storage.max_samples_per_key', 1000);

        $oversized = MetricsSortedSet::query()
            ->select('key')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('key')
            ->havingRaw('COUNT(*) > ?', [$maxSamples])
            ->get();

        foreach ($oversized as $row) {
            $excess = MetricsSortedSet::where('key', $row->key)
                ->orderBy('score')
                ->limit($row->cnt - $maxSamples)
                ->pluck('member');

            MetricsSortedSet::where('key', $row->key)
                ->whereIn('member', $excess)
                ->delete();
        }

        return self::SUCCESS;
    }
}
