<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\Order;
use App\Support\QrCode;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\File;

/*
 * T3-7 (#59): the pickup QR (T3-1) is gone; staff show an ordering QR instead: a big QR of the
 * home page (APP_URL) for customers to scan. Route access for every role is in RouteMatrixTest.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

/**
 * The base64 SVG in the page's ordering QR <img>.
 */
function orderingQrSrc(string $html): string
{
    preg_match('/src="(data:image\/svg\+xml;base64,[^"]+)"[^>]*data-test="ordering-qr"/', $html, $match);

    return $match[1] ?? '';
}

describe('the ordering QR page', function () {
    test('shows a QR of APP_URL and the URL itself, with the tunnel URL in demo mode', function (string $appUrl) {
        config(['app.url' => $appUrl]);

        $html = $this->actingAs(userWithRole('barista'))->get(route('barista.ordering-qr'))->assertOk()->getContent();
        $src = orderingQrSrc($html);

        // bacon renders the same content to the same SVG, so this is the QR of exactly APP_URL.
        expect($src)->toBe(QrCode::dataUri($appUrl))
            ->and($src)->not->toBe(QrCode::dataUri($appUrl.'/'))
            ->and(base64_decode(substr($src, strlen('data:image/svg+xml;base64,'))))->toStartWith('<?xml')->toContain('<svg')
            ->and($html)->toMatch('/data-test="ordering-qr-url"[^>]*>'.preg_quote($appUrl, '/').'</');
    })->with([
        'local' => ['https://tea-alley.test'],
        'demo tunnel' => ['https://demo-name.trycloudflare.com'],
    ]);

    test('the URL is escaped, never raw markup', function () {
        config(['app.url' => 'https://example.test/?a=<b>']);

        $this->actingAs(userWithRole('barista'))->get(route('barista.ordering-qr'))
            ->assertOk()
            ->assertDontSee('?a=<b>', escape: false)
            ->assertSee('https://example.test/?a=&lt;b&gt;', escape: false);
    });

    test('black on white in dark mode too: the panel has no dark: variants', function () {
        $html = $this->actingAs(userWithRole('barista'))->get(route('barista.ordering-qr'))->getContent();

        preg_match('/<section[^>]*data-test="ordering-qr-panel"[^>]*>.*?<\/section>/s', $html, $panel);

        expect($panel[0] ?? '')->toContain('bg-white')->toContain('text-black')->not->toContain('dark:');
    });

    test('is in both languages', function () {
        $this->actingAs(userWithRole('barista'))->withSession(['locale' => 'zh_TW'])
            ->get(route('barista.ordering-qr'))
            ->assertOk()
            ->assertSee('掃描點餐');
    });
});

describe('the barista board', function () {
    test('links to the ordering QR, and no longer to a scanner; Picked up stays', function () {
        Order::factory()->ready()->create();

        $this->actingAs(userWithRole('barista'))->get(route('barista'))
            ->assertOk()
            ->assertSee('data-test="ordering-qr-button"', escape: false)
            ->assertSee(route('barista.ordering-qr'), escape: false)
            ->assertDontSee('data-test="scan-qr-button"', escape: false)
            ->assertSee('data-test="picked-up-button"', escape: false);
    });
});

describe('the pickup QR is gone', function () {
    test('a Ready order\'s page shows no QR', function () {
        $order = Order::factory()->ready()->create();

        $this->actingAs($order->user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertDontSee('data-test="pickup-qr', escape: false)
            ->assertDontSee('data:image/svg+xml', escape: false);
    });

    test('the scanner and confirmation pages are 404, even for staff with a once-valid link', function (string $path) {
        $order = Order::factory()->ready()->create();

        $this->actingAs(userWithRole('barista'))
            ->get(str_replace('{order}', $order->order_number, $path))
            ->assertNotFound();
    })->with([
        '/barista/scan',
        '/barista/pickup/{order}',
        '/barista/pickup/{order}?signature=0000',
    ]);

    test('no page may use the camera any more', function () {
        expect(SecurityHeaders::HEADERS['Permissions-Policy'])->toContain('camera=()')
            ->not->toContain('camera=(self)');
    });

    test('jsQR and the scanner scripts are removed', function () {
        $package = json_decode(File::get(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        expect(array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []))->not->toHaveKey('jsqr')
            ->and(File::exists(resource_path('js/qr-scanner.js')))->toBeFalse()
            ->and(File::exists(resource_path('js/scanner-boot.js')))->toBeFalse()
            ->and(File::exists(resource_path('js/pickup-url.js')))->toBeFalse()
            ->and(File::get(resource_path('js/app.js')))->not->toContain('scanner');
    });
});
