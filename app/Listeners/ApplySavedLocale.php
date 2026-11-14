<?php

namespace App\Listeners;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * On login, a language saved on the account wins over the session's. With nothing
 * saved, the session's current choice (say, Chinese picked as a guest) is kept, and
 * the account is not written to: only the header switch and registration save it.
 */
class ApplySavedLocale
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($user instanceof User && Locale::isSupported($user->locale) && app()->bound('session')) {
            session()->put(SetLocale::SESSION_KEY, $user->locale);
            app()->setLocale(Locale::fromValue($user->locale)->value);
        }
    }
}
