<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $member
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class MetricsSet extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['key', 'member', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
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
}
