<?php

namespace Cbox\LaravelQueueMetrics;

use Closure;
use Illuminate\Http\Request;

class LaravelQueueMetrics
{
    /**
     * The callback that should be used to authenticate Queue Metrics users.
     *
     * @var \Closure|null
     */
    public static $authUsing;

    /**
     * Determine if the given request can access the Queue Metrics dashboard.
     *
     * @return bool
     */
    public function check(Request $request)
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }

    /**
     * Set the callback that should be used to authenticate Queue Metrics users.
     *
     * @return static
     */
    public static function auth(Closure $callback)
    {
        static::$authUsing = $callback;

        return new static;
    }
}
