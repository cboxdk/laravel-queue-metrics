<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Middleware;

use Closure;
use Cbox\LaravelQueueMetrics\LaravelQueueMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
