<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsSortedSet extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['key', 'member', 'score', 'expires_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:4',
            'expires_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $prefix = config('queue-metrics.storage.prefix', 'queue_metrics');

        return $prefix.'_sorted_sets';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('queue-metrics.storage.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }
}
