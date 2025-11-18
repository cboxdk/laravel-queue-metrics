<?php

declare(strict_types=1);

namespace PHPeek\LaravelQueueMetrics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional rate limiting middleware for Prometheus endpoint.
 *
 * Prevents abuse by limiting requests per IP address.
 * Enable in config: 'prometheus.rate_limit.enabled' => true
 */
final readonly class ThrottlePrometheus
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxAttemptsConfig = config('queue-metrics.prometheus.rate_limit.max_attempts', 60);
        $maxAttempts = is_numeric($maxAttemptsConfig) ? (int) $maxAttemptsConfig : 60;

        $decayMinutesConfig = config('queue-metrics.prometheus.rate_limit.decay_minutes', 1);
        $decayMinutes = is_numeric($decayMinutesConfig) ? (int) $decayMinutesConfig : 1;

        $key = 'prometheus:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, $decayMinutes * 60);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }
}
