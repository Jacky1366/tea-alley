<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the app locale for every web request, Livewire updates included (they go
 * through the web group too). First match wins:
 *
 *   1. session('locale')      the guest's or user's current choice
 *   2. users.locale           a signed-in user's saved choice (copied into the session)
 *   3. config('app.locale')   English
 *
 * Unsupported values are ignored, so a bad session or database value means English.
 */
class SetLocale
{
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request)->value);

        return $next($request);
    }

    private function resolve(Request $request): Locale
    {
        $session = $request->session();
        $chosen = $session->get(self::SESSION_KEY);

        if (Locale::isSupported($chosen)) {
            return Locale::fromValue($chosen);
        }

        $user = $request->user();

        if ($user instanceof User && Locale::isSupported($user->locale)) {
            $session->put(self::SESSION_KEY, $user->locale);

            return Locale::fromValue($user->locale);
        }

        return Locale::fromValue(config('app.locale'));
    }
}
