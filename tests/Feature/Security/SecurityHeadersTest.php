<?php

use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Http\Middleware\SecurityHeaders;
use App\Models\MenuItem;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * T3-3 (#51): the browser security headers are on every kind of response, and a response's
 * own header wins.
 */
test('every kind of response carries the security headers', function (string $kind) {
    $this->seed(RoleSeeder::class);

    $response = match ($kind) {
        'public page' => $this->get('/'),
        'admin panel' => $this->actingAs(userWithRole('admin'))->get('/admin'),
        'error page' => $this->get('/no-such-page'),
        'webhook' => $this->post('/stripe/webhook'),
        'redirect' => $this->get('/checkout'),
    };

    foreach (SecurityHeaders::HEADERS as $name => $value) {
        $response->assertHeader($name, $value);
    }
})->with(['public page', 'admin panel', 'error page', 'webhook', 'redirect']);

test('a header the response already set is kept', function () {
    Route::get('/__own-csp', fn () => response('x')->header('Content-Security-Policy', "default-src 'none'"));

    $this->get('/__own-csp')->assertHeader('Content-Security-Policy', "default-src 'none'");
});

test('no script-src policy that would break Alpine, Livewire, or Vite', function () {
    expect(SecurityHeaders::HEADERS['Content-Security-Policy'])->toBe("frame-ancestors 'self'");
});

test('private files are no longer served from /storage', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});

test('drink photo uploads still work: Livewire uploads through its own signed route, not /storage', function () {
    Storage::fake('public');
    $this->seed(RoleSeeder::class);
    $this->actingAs(userWithRole('manager'));
    $drink = MenuItem::factory()->create();

    Livewire::test(EditMenuItem::class, ['record' => $drink->getRouteKey()])
        ->fillForm(['photo_path' => UploadedFile::fake()->image('tea.png', 400, 400)])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = $drink->fresh()->photo_path;

    expect($path)->toBeString()
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(Route::has('livewire.upload-file'))->toBeTrue()
        ->and(Route::has('livewire.preview-file'))->toBeTrue();
});
