<?php

use App\Providers\AppServiceProvider;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * T3-3 (#51), NIST SP 800-63B: at least 8 characters and not a known breached password; no
 * composition rules; the same in every environment. The breach check asks Have I Been Pwned
 * (k-anonymity: only the first 5 characters of the SHA-1 leave the server).
 */
function signUp(string $password): TestResponse
{
    // With a CSRF token: tests skip the CSRF check only while the environment is "testing".
    return test()->withSession(['_token' => 'csrf-token'])->post(route('register.store'), [
        '_token' => 'csrf-token',
        'name' => 'Mei',
        'email' => 'mei'.uniqid().'@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]);
}

/**
 * Replaces TestCase's default "not breached" answer: with Http::fake(), the first matching stub
 * wins, so the default has to go rather than be faked over.
 *
 * @param  Closure|PromiseInterface  $answer
 */
function fakePwned(mixed $answer): void
{
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake(['api.pwnedpasswords.com/*' => $answer]);
}

/** Have I Been Pwned's range answer, listing $password as seen $count times. */
function pwnedRange(string $password, int $count = 12345): string
{
    return substr(strtoupper(sha1($password)), 5).':'.$count."\r\nAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:1";
}

test('8 characters with no digits, capitals, or symbols is enough', function () {
    signUp('quietteahouse')->assertSessionHasNoErrors();
});

test('7 characters is too short', function () {
    signUp('abcdefg')->assertSessionHasErrors('password');
});

test('a breached password is refused', function () {
    fakePwned(Http::response(pwnedRange('password123')));

    signUp('password123')->assertSessionHasErrors('password');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr(strtoupper(sha1('password123')), 0, 5));
});

test('the same rules apply in production', function () {
    app()->detectEnvironment(fn () => 'production');
    fakePwned(Http::response(pwnedRange('password123')));

    signUp('password123')->assertSessionHasErrors('password');
    signUp('abcdefg')->assertSessionHasErrors('password');
});

test('if Have I Been Pwned is unreachable, the password is accepted (the check fails open)', function () {
    fakePwned(fn () => throw new ConnectionException('Connection timed out'));

    signUp('password123')->assertSessionHasNoErrors();
});

test('if Have I Been Pwned answers with an error, the password is accepted too', function () {
    fakePwned(Http::response('', 503));

    signUp('password123')->assertSessionHasNoErrors();
});

test('registration waits at most a few seconds for Have I Been Pwned', function () {
    $timeout = (new ReflectionProperty(app(UncompromisedVerifier::class), 'timeout'))->getValue(app(UncompromisedVerifier::class));

    expect($timeout)->toBe(AppServiceProvider::BREACHED_PASSWORD_TIMEOUT_SECONDS)
        ->and($timeout)->toBeLessThanOrEqual(5);
});
