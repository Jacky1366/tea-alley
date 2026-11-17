<?php

use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/*
 * T3-3 (#51): custom 403, 404, 419, 429, 500, and 503 pages. Both languages on every page
 * (the language isn't known yet for an unknown URL or an expired session), a link home, and
 * nothing from the exception, with APP_DEBUG=false as in the demo.
 */
beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->group(function () {
        Route::get('/__error/403', fn () => abort(403, 'SECRET-403-MESSAGE'));
        Route::get('/__error/404', fn () => abort(404, 'SECRET-404-MESSAGE'));
        Route::get('/__error/419', fn () => throw new TokenMismatchException('SECRET-419-MESSAGE'));
        Route::get('/__error/429', fn () => throw new TooManyRequestsHttpException(42, 'SECRET-429-MESSAGE'));
        Route::get('/__error/500', fn () => throw new RuntimeException('SECRET-500-MESSAGE in '.__FILE__));
        Route::get('/__error/503', fn () => abort(503, 'SECRET-503-MESSAGE'));
        Route::get('/__error/sql', fn () => throw new QueryException(
            'mysql',
            'select * from users where password = ?',
            ['SECRET-BINDING'],
            new PDOException('SQLSTATE[42S02]: SECRET-SQL-ERROR'),
        ));
    });
});

test('each error page is bilingual, has a link home, and shows nothing from the exception', function (int $code, string $en, string $zh) {
    $response = $this->get("/__error/{$code}")->assertStatus($code);

    $response->assertSee('data-code="'.$code.'"', escape: false)
        ->assertSee($en)
        ->assertSee($zh)
        ->assertSee('href="'.url('/').'"', escape: false)
        ->assertSee('回到菜單')
        ->assertDontSee('SECRET-')
        ->assertDontSee(base_path())
        ->assertDontSee('Stack trace');
})->with([
    '403' => [403, "You don't have access to this page.", '你沒有權限查看這個頁面。'],
    '404' => [404, "We couldn't find that page.", '找不到這個頁面。'],
    '419' => [419, 'This page has expired. Please refresh the page and try again.', '頁面已過期，請重新整理頁面後再試一次。'],
    '429' => [429, 'Please wait 42 seconds', '請等 42 秒後再試'],
    '500' => [500, 'Something went wrong on our side.', '系統發生問題，請稍後再試。'],
    '503' => [503, "We're down for a short break.", '系統暫停服務一下'],
]);

test('a database error shows the 500 page without the SQL, the bindings, or the driver message', function () {
    $this->get('/__error/sql')->assertStatus(500)
        ->assertSee('Something went wrong on our side.')
        ->assertDontSee('select * from')
        ->assertDontSee('SECRET-')
        ->assertDontSee('SQLSTATE');
});

test('an unknown URL gets the bilingual 404 page, outside any route middleware', function () {
    $this->get('/no-such-page')->assertNotFound()
        ->assertSee("We couldn't find that page.")
        ->assertSee('找不到這個頁面。');
});

test('the error pages need no database: they still render when every query fails', function (string $url, int $status) {
    DB::beforeExecuting(fn () => throw new RuntimeException('SECRET-the database is down'));

    $this->get($url)->assertStatus($status)
        ->assertSee('data-code="'.$status.'"', escape: false)
        ->assertDontSee('SECRET-');
})->with([
    'unknown URL' => ['/no-such-page', 404],
    'exception' => ['/__error/500', 500],
]);

test('a JSON request still gets JSON, not the HTML page', function () {
    $this->getJson('/__error/500')->assertStatus(500)->assertJson(['message' => 'Server Error']);
});
