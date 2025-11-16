<?php

namespace PHPeek\LaravelQueueMetrics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \PHPeek\LaravelQueueMetrics\LaravelQueueMetrics
 */
class LaravelQueueMetrics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PHPeek\LaravelQueueMetrics\LaravelQueueMetrics::class;
    }
}
