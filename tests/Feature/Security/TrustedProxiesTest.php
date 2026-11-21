<?php

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * T3-3 (#51): only a proxy on this machine (the demo tunnel's client) is trusted to say who
 * the visitor is and whether they used https. Anyone else's X-Forwarded-* headers are ignored.
 */
beforeEach(function () {
    Route::middleware('web')->get('/__whoami', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->secure(),
        'url' => url('/menu'),
    ]);
});

function forwardedFrom(string $remoteAddress): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders([
            'X-Forwarded-For' => '203.0.113.50',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ])
        ->get('http://tea-alley.test/__whoami');
}

test('from the local tunnel client, the visitor\'s IP and https are believed', function (string $loopback) {
    forwardedFrom($loopback)->assertExactJson([
        'ip' => '203.0.113.50',
        'secure' => true,
        'url' => 'https://tea-alley.test/menu',
    ]);
})->with(['127.0.0.1', '::1']);

test('from anywhere else, forwarded headers are ignored (a visitor can\'t fake their IP or scheme)', function () {
    forwardedFrom('198.51.100.7')->assertExactJson([
        'ip' => '198.51.100.7',
        'secure' => false,
        'url' => 'http://tea-alley.test/menu',
    ]);
});

test('a visitor\'s own X-Forwarded-For through the tunnel doesn\'t replace the address the tunnel saw', function () {
    // The tunnel appends the address it saw; Laravel reads from the right, skipping trusted proxies.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeaders(['X-Forwarded-For' => '10.9.9.9, 203.0.113.50'])
        ->get('http://tea-alley.test/__whoami')
        ->assertJsonPath('ip', '203.0.113.50');
});

/*
 * #67: which proxies are trusted is config (TRUSTED_PROXIES), loopback by default. On Laravel
 * Cloud it is REMOTE_ADDR: the app is reachable only through Cloud's edge, so the direct peer is
 * Cloud's proxy, and the client is the address that proxy appended (the right-most entry).
 */
function trustProxiesFromEnv(?string $value): void
{
    // env() reads $_SERVER, then $_ENV, then getenv(); a .env with TRUSTED_PROXIES (CI copies
    // .env.example) fills the first two, so all three are set, and restored afterwards.
    $saved = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null, getenv('TRUSTED_PROXIES')];
    $set = function (?string $server, ?string $env, string|false $getenv): void {
        if ($server === null) {
            unset($_SERVER['TRUSTED_PROXIES']);
        } else {
            $_SERVER['TRUSTED_PROXIES'] = $server;
        }
        if ($env === null) {
            unset($_ENV['TRUSTED_PROXIES']);
        } else {
            $_ENV['TRUSTED_PROXIES'] = $env;
        }
        $getenv === false ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES='.$getenv);
    };

    $set($value, $value, $value ?? false);

    try {
        config(['tea-alley.trusted_proxies' => (require config_path('tea-alley.php'))['trusted_proxies']]);
    } finally {
        $set(...$saved);
    }

    TrustedProxies::apply();
}

function fromCloudProxy(string $forwardedFor): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->withHeaders(['X-Forwarded-For' => $forwardedFor, 'X-Forwarded-Proto' => 'https'])
        ->get('http://tea-alley.test/__whoami');
}

test('TRUSTED_PROXIES defaults to loopback only', function () {
    trustProxiesFromEnv(null);

    expect(config('tea-alley.trusted_proxies'))->toBe(['127.0.0.1', '::1']);
    forwardedFrom('127.0.0.1')->assertJsonPath('ip', '203.0.113.50');
    forwardedFrom('198.51.100.7')->assertJsonPath('ip', '198.51.100.7');
});

test('configured proxies are honoured, and anyone else is still ignored', function () {
    trustProxiesFromEnv(' 10.0.0.0/8 , 192.0.2.1,');

    expect(config('tea-alley.trusted_proxies'))->toBe(['10.0.0.0/8', '192.0.2.1']);
    forwardedFrom('10.1.2.3')->assertJsonPath('ip', '203.0.113.50')->assertJsonPath('secure', true);
    forwardedFrom('192.0.2.1')->assertJsonPath('ip', '203.0.113.50');
    forwardedFrom('127.0.0.1')->assertJsonPath('ip', '127.0.0.1')->assertJsonPath('secure', false);
});

test('REMOTE_ADDR (Cloud): a visitor\'s own X-Forwarded-For entry is ignored; the IP is the one the proxy appended', function () {
    trustProxiesFromEnv('REMOTE_ADDR');

    fromCloudProxy('6.6.6.6, 203.0.113.9')->assertJsonPath('ip', '203.0.113.9')->assertJsonPath('secure', true);
    fromCloudProxy('203.0.113.9')->assertJsonPath('ip', '203.0.113.9');
});

test('why never "*": Laravel trusts every address, and the visitor\'s left-most entry wins', function () {
    // Documents the framework behaviour behind the rule, so a future change of TRUSTED_PROXIES
    // to '*' is a known decision: with this, per-IP rate limits can be dodged.
    TrustProxies::at('*');

    fromCloudProxy('6.6.6.6, 203.0.113.9')->assertJsonPath('ip', '6.6.6.6');
});

test('TRUSTED_PROXIES=* (a mistake) fails safe: it is not Laravel\'s "*", so nobody is trusted', function () {
    trustProxiesFromEnv('*');

    fromCloudProxy('6.6.6.6, 203.0.113.9')->assertJsonPath('ip', '10.20.30.40')->assertJsonPath('secure', false);
});

test('in production with REMOTE_ADDR, a forged Host or X-Forwarded-Host is a 400; the real host is fine', function () {
    trustProxiesFromEnv('REMOTE_ADDR');
    config(['app.url' => 'https://tea-alley-main.laravel.cloud']);
    $this->app['env'] = 'production';

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->get('http://evil.com/up')->assertBadRequest();
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->withHeaders(['X-Forwarded-Host' => 'evil.com'])
            ->get('http://tea-alley-main.laravel.cloud/up')->assertBadRequest();
        $this->flushHeaders()
            ->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://tea-alley-main.laravel.cloud/up')->assertOk();
    } finally {
        $this->app['env'] = 'testing';
        Request::setTrustedHosts([]);
    }
});
