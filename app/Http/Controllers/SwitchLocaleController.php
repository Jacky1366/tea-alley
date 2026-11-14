<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The header's language switch (POST + CSRF). Stores the choice in the session, and on
 * the account too when signed in, then goes back to the page the customer was on.
 * An unsupported value is treated as English rather than rejected.
 */
class SwitchLocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = Locale::fromValue($request->input('locale'));

        $request->session()->put(SetLocale::SESSION_KEY, $locale->value);

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['locale' => $locale->value])->save();
        }

        return redirect()->to($this->returnUrl($request));
    }

    /**
     * The last full page the session recorded (Livewire's AJAX requests don't change it),
     * never the Referer header, and only if it's on this site.
     */
    private function returnUrl(Request $request): string
    {
        $previous = $request->session()->previousUrl();

        if (is_string($previous) && str_starts_with($previous, $request->getSchemeAndHttpHost().'/')) {
            return $previous;
        }

        return route('home');
    }
}
