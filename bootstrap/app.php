<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only APP_URL's host (no subdomains): a request with any other Host header is
        // rejected, so nothing that builds URLs from the request host can be pointed elsewhere.
        // Laravel skips this check in the local environment and in tests.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

            return is_string($host) ? ['^'.preg_quote($host).'$'] : [];
        }, subdomains: false);

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        // After the session and auth: reads session('locale'), then users.locale.
        $middleware->web(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
