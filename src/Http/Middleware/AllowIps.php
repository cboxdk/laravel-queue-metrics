<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AllowIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('queue-metrics.allowed_ips');

        if ($allowedIps === null) {
            return $next($request);
        }

        if (! in_array($request->ip(), $allowedIps, true)) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
