<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * T3-3: browser security headers on every response (global middleware, so error pages,
 * Livewire, and Filament get them too). A header the response already set wins: Laravel's
 * private file route, for example, sends its own stricter Content-Security-Policy.
 *
 * Deliberately not sent:
 *  - a script-src Content-Security-Policy: Alpine (non-CSP build) needs unsafe-eval, and
 *    Livewire and Vite inject inline scripts, so it would break the app;
 *  - Strict-Transport-Security: the demo runs on a tunnel domain we don't own.
 * The Stripe Checkout redirect is a top-level navigation, unaffected by any of these.
 */
class SecurityHeaders
{
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        // Same origin only: no one can frame the app (clickjacking), but our own pages can,
        // e.g. Livewire's error modal and the phone-width iframes used in browser checks.
        'X-Frame-Options' => 'SAMEORIGIN',
        'Content-Security-Policy' => "frame-ancestors 'self'",
        // The camera stays available to our own pages for T3-1's QR scanner.
        'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
