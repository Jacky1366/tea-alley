<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limits for logged-in Livewire actions (T3-3). Keyed by user id, never by IP: a whole
 * classroom shares one public IP, and everyone coming through the demo tunnel can look alike.
 * Livewire actions don't pass through route throttle middleware, so the actions call this.
 */
final class ActionLimiter
{
    public const CHECKOUT = 'checkout';

    public const RETRY_PAYMENT = 'retry-payment';

    /** Attempts allowed per minute, per user. */
    public const PER_MINUTE = [
        self::CHECKOUT => 5,
        self::RETRY_PAYMENT => 5,
    ];

    /**
     * Counts one attempt. Null when it's allowed; otherwise the seconds to wait (the attempt
     * isn't counted).
     */
    public static function attempt(string $action, User $user): ?int
    {
        $key = self::key($action, $user);

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE[$action])) {
            return max(1, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    public static function key(string $action, User $user): string
    {
        return $action.':user:'.$user->id;
    }

    public static function message(int $seconds): string
    {
        return __('Too many attempts. Please wait :seconds seconds and try again.', ['seconds' => $seconds]);
    }
}
