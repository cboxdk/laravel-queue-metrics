<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueMetrics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to queue metrics endpoints by IP address.
 *
 * When deployed behind a reverse proxy (e.g., Nginx, AWS ALB),
 * ensure Laravel's TrustProxies middleware is configured so that
 * $request->ip() returns the real client IP, not the proxy IP.
 */
final class AllowIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('queue-metrics.allowed_ips');

        // Allow access if no IPs are configured or config is invalid
        if ($allowedIps === null || ! is_array($allowedIps)) {
            return $next($request);
        }

        if (! in_array($request->ip(), $allowedIps, true)) {
            abort(403, 'Access denied');
        }

        return $next($request);
    }
}
