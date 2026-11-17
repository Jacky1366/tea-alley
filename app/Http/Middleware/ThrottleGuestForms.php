<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * T3-3: rate limits for Fortify forms that Fortify itself doesn't throttle. Fortify only
 * lets us name a limiter for login, two-factor, and passkeys, so this web-group middleware
 * applies the named limiters (FortifyServiceProvider) by route name. Both forms can send an
 * email through the shop's Gmail account. Over the limit: a 429 with Retry-After, shown by
 * resources/views/errors/429.blade.php.
 */
class ThrottleGuestForms
{
    /** Route name => named rate limiter. */
    public const LIMITERS = [
        'register.store' => 'register',
        'password.email' => 'forgot-password',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if (is_string($name) && isset(self::LIMITERS[$name])) {
            return $this->throttle->handle($request, $next, self::LIMITERS[$name]);
        }

        return $next($request);
    }
}
