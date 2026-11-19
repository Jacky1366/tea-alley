<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ThrottleGuestForms;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Webhooks: outside the web group, so no session or CSRF (routes/webhooks.php).
        then: function (): void {
            Route::middleware([])->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only APP_URL's host (no subdomains): a request with any other Host header is
        // rejected, so nothing that builds URLs from the request host can be pointed elsewhere.
        // Laravel skips this check in the local environment and in tests.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

            return is_string($host) ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);

        // T3-3: the demo tunnel's client runs on this machine and connects to Herd locally, so
        // only a local process can be the proxy. Trusting just loopback means a visitor's own
        // X-Forwarded-* headers are never believed, while the tunnel's are: request()->ip() is
        // the visitor (login throttling), and secure()/url() see https. Never '*'.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // T3-6a: TrustHosts must run after TrustProxies. Laravel puts it first, and TrustProxies
        // reads the host (its Forge/Vapor check) before it trusts the proxy: through the demo
        // tunnel (Host rewritten to tea-alley.test for Herd, the public host in
        // X-Forwarded-Host) that read is checked against APP_URL's host and fails, so every
        // request was a 400. TrustHosts only sets the allowed hosts, checked whenever the host
        // is read; after TrustProxies, that is the forwarded host for the loopback proxy only.
        $middleware->replace(TrustHosts::class, []);
        $middleware->replace(TrustProxies::class, [TrustProxies::class, TrustHosts::class]);

        // Every response, including error pages, Livewire, Filament, and the webhook.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // After the session and auth: reads session('locale'), then users.locale.
        $middleware->web(append: [
            SetLocale::class,
            ThrottleGuestForms::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
