<?php

namespace Cbox\LaravelQueueMetrics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Cbox\LaravelQueueMetrics\LaravelQueueMetrics
 */
class LaravelQueueMetrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Cbox\LaravelQueueMetrics\LaravelQueueMetrics::class;
    }
}
