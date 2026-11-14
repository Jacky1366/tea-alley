<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;

/*
 * TrustHosts only checks outside the local environment and outside tests, so these tests
 * switch the app environment to production for the request. Request::setTrustedHosts() is
 * global, so it is cleared again afterwards.
 */
beforeEach(function () {
    config(['app.url' => 'https://tea-alley.example']);
    $this->app['env'] = 'production';
});

afterEach(function () {
    $this->app['env'] = 'testing';
    Request::setTrustedHosts([]);
});

test('the app runs TrustHosts', function () {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue();
});

test('APP_URL\'s host is served', function () {
    $this->get('https://tea-alley.example/')->assertOk();
});

test('a forged Host header is rejected with 400', function (string $host) {
    // The test client takes the Host header from the URL.
    $this->get("https://{$host}/")->assertBadRequest();
})->with([
    'another domain' => 'evil.example',
    'a subdomain' => 'evil.tea-alley.example',
    'APP_URL\'s host as a prefix' => 'tea-alley.example.evil.example',
]);

test('a forged Host header is rejected on the barista board too', function () {
    $this->get('https://evil.example/barista')->assertBadRequest();
});
