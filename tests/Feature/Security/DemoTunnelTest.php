<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * T3-6a (#57): demo mode behind a Cloudflare quick tunnel. Measured on this machine:
 * cloudflared runs here and connects to Herd's loopback listener (127.0.0.1:60) with the Host
 * rewritten to tea-alley.test (so Herd picks the site), and sends the public hostname in
 * X-Forwarded-Host, the scheme in X-Forwarded-Proto, and appends the client to X-Forwarded-For.
 * In demo mode APP_ENV=production (TrustHosts on) and APP_URL is the tunnel URL.
 */
const TUNNEL_HOST = 'demo-name.trycloudflare.com';

beforeEach(function () {
    config(['app.url' => 'https://'.TUNNEL_HOST]);
    $this->app['env'] = 'production';

    Route::middleware('web')->get('/__tunnel-whoami', fn (Request $request) => [
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'secure' => $request->secure(),
        'url' => url('/menu'),
    ]);
});

afterEach(function () {
    $this->app['env'] = 'testing';
    Request::setTrustedHosts([]);
});

/**
 * A request as cloudflared delivers it, from $remote, with a client who tried to spoof their IP.
 */
function throughTunnel(string $remote, string $path): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remote])
        ->withHeaders([
            'X-Forwarded-Host' => TUNNEL_HOST,
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '10.6.6.6,203.0.113.9',
        ])
        ->get('http://tea-alley.test'.$path);
}

test('TrustHosts runs right after TrustProxies, so it checks the forwarded host', function () {
    $global = app(Kernel::class)->getGlobalMiddleware();
    $proxies = array_search(TrustProxies::class, $global, true);

    expect($proxies)->not->toBeFalse()
        ->and($global[$proxies + 1] ?? null)->toBe(TrustHosts::class)
        ->and(array_keys($global, TrustHosts::class, true))->toHaveCount(1);
});

test('through the tunnel: TrustHosts accepts it, and the host, scheme, URLs, and client IP are the visitor\'s', function () {
    throughTunnel('127.0.0.1', '/__tunnel-whoami')->assertOk()->assertExactJson([
        'ip' => '203.0.113.9',          // the address the tunnel appended; the client's own entry is ignored
        'host' => TUNNEL_HOST,
        'secure' => true,
        'url' => 'https://'.TUNNEL_HOST.'/menu',
    ]);
});

test('redirects point at the tunnel URL (login, intended URL)', function () {
    throughTunnel('127.0.0.1', '/checkout')->assertRedirect('https://'.TUNNEL_HOST.'/login');
});

test('error pages link home to the tunnel URL', function () {
    throughTunnel('127.0.0.1', '/no-such-page')->assertNotFound()
        ->assertSee('href="https://'.TUNNEL_HOST.'"', escape: false);
});

test('the same headers from anywhere but loopback are ignored, so TrustHosts rejects the request', function () {
    throughTunnel('198.51.100.7', '/__tunnel-whoami')->assertBadRequest();
});

test('from a non-loopback address, a forged Host is rejected, even when it claims the real host in X-Forwarded-Host', function (array $headers) {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
        ->withHeaders($headers)
        ->get('http://evil.com/up')
        ->assertBadRequest();
})->with([
    'Host: evil.com' => [[]],
    'Host: evil.com + X-Forwarded-Host: the tunnel' => [['X-Forwarded-Host' => TUNNEL_HOST, 'X-Forwarded-Proto' => 'https']],
]);

test('one host in demo mode: tea-alley.test itself is rejected while APP_URL is the tunnel', function () {
    $this->get('https://tea-alley.test/')->assertBadRequest();
});

test('the demo snippet blocks Herd Studio on decoded, slash-merged, case-insensitive paths, for this host only', function () {
    $directives = preg_replace('/^\s*#.*$/m', '', file_get_contents(base_path('deploy/herd/demo-mode.conf')));

    // Matched on $uri (decoded, slashes merged), not $request_uri (raw: /%5F%5Fherd would slip by),
    // case-insensitively (nginx on Windows matches locations that way), before any location.
    expect($directives)->toContain('if ($uri ~* "^/+(__herd|herd-studio)(/|$)")')
        ->and($directives)->not->toContain('$request_uri')
        ->and($directives)->toContain('if ($host = "tea-alley.test")')
        ->and($directives)->toContain('if ($tea_alley_demo_block = "host+path")')
        // With a body: a bare `return 404` would go through Herd's error_page and become a 302.
        ->and(preg_match_all('/return 404 "Not Found";/', $directives))->toBe(2)
        ->and($directives)->not->toMatch('/return 404;/');

    // The same regex nginx will run, against the paths nginx hands it after decoding and merging.
    $pattern = '#^/+(__herd|herd-studio)(/|$)#i';
    foreach (['/__herd/ai/ws', '//__herd/ai/ws', '/__HERD/ai/ws', '/herd-studio/loader.js', '/Herd-Studio/loader.js', '/__herd', '/herd-studio'] as $blocked) {
        expect(preg_match($pattern, $blocked))->toBe(1, $blocked);
    }
    foreach (['/up', '/herdsman', '/__herded/x', '/app/key', '/menu/herd-studio'] as $allowed) {
        expect(preg_match($pattern, $allowed))->toBe(0, $allowed);
    }
});

test('the demo snippet forwards only /app/ to Reverb, guards the host, and verifies TLS', function () {
    $directives = preg_replace('/^\s*#.*$/m', '', file_get_contents(base_path('deploy/herd/demo-mode.conf')));

    expect($directives)->toContain('location ^~ /app/ {')
        ->and(preg_match_all('/\blocation\b/', $directives))->toBe(1)
        ->and($directives)->not->toContain('/apps')
        ->and($directives)->toContain('if ($host != "tea-alley.test")')
        ->and($directives)->toContain('proxy_ssl_verify on;')
        ->and($directives)->toContain('proxy_ssl_trusted_certificate "{{HERD_CA}}";')
        ->and($directives)->not->toMatch('/proxy_ssl_verify\s+off/');
});
