<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $key
 * @property array<string, mixed> $data
 * @property Carbon|null $expires_at
 * @property Carbon|null $updated_at
 */
class MetricsHash extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'data', 'expires_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'expires_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $prefix = is_string($raw = config('queue-metrics.storage.prefix', 'queue_metrics')) ? $raw : 'queue_metrics';

        return $prefix.'_hashes';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('queue-metrics.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }
}
