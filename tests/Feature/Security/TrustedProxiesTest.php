<?php

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
