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

test('one host in demo mode: tea-alley.test itself is rejected while APP_URL is the tunnel', function () {
    $this->get('https://tea-alley.test/')->assertBadRequest();
});

test('the Reverb proxy snippet forwards only /app/, guards the host, and verifies TLS', function () {
    $snippet = file_get_contents(base_path('deploy/herd/reverb-proxy.conf'));
    $directives = preg_replace('/^\s*#.*$/m', '', $snippet);

    expect($directives)->toContain('location ^~ /app/ {')
        ->and(preg_match_all('/\blocation\b/', $directives))->toBe(1)
        ->and($directives)->not->toContain('/apps')
        ->and($directives)->toContain('if ($host != "tea-alley.test")')
        ->and($directives)->toContain('proxy_ssl_verify on;')
        ->and($directives)->toContain('proxy_ssl_trusted_certificate "{{HERD_CA}}";')
        ->and($directives)->not->toMatch('/proxy_ssl_verify\s+off/');
});
