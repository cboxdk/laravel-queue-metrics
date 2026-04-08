<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Models;

use Illuminate\Database\Eloquent\Model;

class MetricsKey extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'expires_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $prefix = config('queue-metrics.storage.prefix', 'queue_metrics');

        return $prefix.'_keys';
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
