<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * #67: the proxies TrustProxies believes, from config('tea-alley.trusted_proxies')
 * (TRUSTED_PROXIES). Applied when the app boots, not in bootstrap/app.php: the middleware
 * callback there runs before the configuration is loaded. The headers stay in bootstrap/app.php.
 */
final class TrustedProxies
{
    public static function apply(): void
    {
        /** @var list<string> $proxies */
        $proxies = config()->array('tea-alley.trusted_proxies');

        TrustProxies::at($proxies);
    }
}
