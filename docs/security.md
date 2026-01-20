# Security

The Laravel Queue Metrics dashboard exposes sensitive information about your job queues. It is crucial to secure these endpoints in production environments.

## Dashboard Authorization

By default, the dashboard routes are protected by the `Authorize` middleware, which checks a gate. In a local environment, access is allowed automatically. For production, you **must** configure the authorization logic.

### Defining Access Logic

You can define who has access to the dashboard using the `LaravelQueueMetrics::auth` method. Typically, you should place this in the `boot` method of your `AppServiceProvider` or `AuthServiceProvider`.

```php
use Cbox\LaravelQueueMetrics\LaravelQueueMetrics;

public function boot(): void
{
    LaravelQueueMetrics::auth(function ($request) {
        // Return true to allow access
        return app()->environment('local') ||
               in_array($request->user()?->email, [
                   'admin@example.com',
               ]);
    });
}
```

### Custom Middleware

If you prefer to use your own middleware stack, you can configure it in `config/queue-metrics.php`:

```php
'middleware' => ['web', 'auth', 'admin'],
```

Or you can add your custom middleware to the default list:

```php
'middleware' => ['api', \Cbox\LaravelQueueMetrics\Http\Middleware\Authorize::class],
```

## IP Whitelisting

You can also restrict access by IP address using the `allowed_ips` configuration option in `config/queue-metrics.php` (this requires your own middleware implementation to check, or reliance on web server config).

## CSP and Headers

The dashboard API returns JSON data. Ensure your frontend application handling this data implements proper Content Security Policy (CSP) headers.
