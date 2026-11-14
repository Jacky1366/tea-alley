<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel stays in English. Registered as persistent panel middleware, so
 * Livewire also re-applies it to the panel's /livewire/update requests, which run
 * through the web group (and SetLocale) first.
 */
class ForceEnglishLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Locale::English->value);

        return $next($request);
    }
}
