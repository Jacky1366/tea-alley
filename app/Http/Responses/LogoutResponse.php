<?php

namespace App\Http\Responses;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Fortify's logout response, plus keeping the language: Fortify has already invalidated
 * the session (so the cart and everything else is gone), and this puts only the current
 * locale back into the new one, so the customer isn't dropped back into English.
 */
class LogoutResponse implements LogoutResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        // SetLocale resolved this at the start of the request, before the session was invalidated.
        $request->session()->put(SetLocale::SESSION_KEY, app()->getLocale());

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect(Fortify::redirects('logout', '/'));
    }
}
