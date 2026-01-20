<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Middleware;

use Cbox\LaravelQueueMetrics\LaravelQueueMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Handle the incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return app(LaravelQueueMetrics::class)->check($request)
            ? $next($request)
            : abort(403);
    }
}
