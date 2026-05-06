<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property string $member
 * @property Carbon|null $created_at
 * @property Carbon|null $expires_at
 *
 * @internal
 */
final class MetricsSet extends Model
{
    /** @var list<string> */
    private const AGGREGATED_DISCOVERY_KEYS = [
        'discovery:queues',
        'discovery:jobs',
    ];

    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['key', 'member', 'created_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        $now = now();
        $rawCutoff = $now->copy()->subSeconds(self::rawTtl());
        $aggregatedCutoff = $now->copy()->subSeconds(self::aggregatedTtl());

        return $query->where(function (Builder $q) use ($aggregatedCutoff, $now, $rawCutoff) {
            $q->where(function (Builder $explicitExpiry) use ($now) {
                $explicitExpiry->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            })->orWhere(function (Builder $legacyActiveWorkers) use ($rawCutoff) {
                $legacyActiveWorkers
                    ->whereNull('expires_at')
                    ->where('key', 'active_workers')
                    ->where('created_at', '<=', $rawCutoff);
            })->orWhere(function (Builder $legacyAggregatedDiscovery) use ($aggregatedCutoff) {
                $legacyAggregatedDiscovery
                    ->whereNull('expires_at')
                    ->whereIn('key', self::AGGREGATED_DISCOVERY_KEYS)
                    ->where('created_at', '<=', $aggregatedCutoff);
            })->orWhere(function (Builder $legacyRawDiscovery) use ($rawCutoff) {
                $legacyRawDiscovery
                    ->whereNull('expires_at')
                    ->where('key', 'like', 'discovery:server_jobs:%')
                    ->where('created_at', '<=', $rawCutoff);
            });
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleForKey(Builder $query, string $key): Builder
    {
        $ttl = self::ttlSecondsForKey($key);

        return $query
            ->where('key', $key)
            ->where(function (Builder $q) use ($ttl) {
                $q->where(function (Builder $explicitExpiry) {
                    $explicitExpiry->whereNotNull('expires_at')->where('expires_at', '>', now());
                });

                if ($ttl === null) {
                    $q->orWhereNull('expires_at');

                    return;
                }

                $q->orWhere(function (Builder $legacyTransient) use ($ttl) {
                    $legacyTransient
                        ->whereNull('expires_at')
                        ->where('created_at', '>', now()->subSeconds($ttl));
                });
            });
    }

    public function getTable(): string
    {
        $prefix = is_string($raw = config('queue-metrics.storage.prefix', 'queue_metrics')) ? $raw : 'queue_metrics';

        return $prefix.'_sets';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('queue-metrics.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    private static function ttlSecondsForKey(string $key): ?int
    {
        if ($key === 'active_workers' || str_starts_with($key, 'discovery:server_jobs:')) {
            return self::rawTtl();
        }

        if (in_array($key, self::AGGREGATED_DISCOVERY_KEYS, true)) {
            return self::aggregatedTtl();
        }

        return null;
    }

    private static function rawTtl(): int
    {
        $ttl = config('queue-metrics.storage.ttl.raw', 3600);

        return is_numeric($ttl) ? (int) $ttl : 3600;
    }

    private static function aggregatedTtl(): int
    {
        $ttl = config('queue-metrics.storage.ttl.aggregated', 86400);

        return is_numeric($ttl) ? (int) $ttl : 86400;
    }
}
