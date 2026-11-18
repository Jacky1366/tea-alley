<?php

use App\Http\Controllers\SwitchLocaleController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::menu')->name('home');

// Public on purpose: browsing, building a drink, and the cart don't need an account; only checkout does.
Route::livewire('/menu/{item}', 'pages::drink')->name('menu.show');
Route::livewire('/cart', 'pages::cart')->name('cart');

// Public on purpose: guests switch language too. CSRF-protected by the web group.
Route::post('/locale', SwitchLocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.switch');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('/checkout', 'pages::checkout')->name('checkout');

    Route::livewire('/orders', 'pages::orders.index')->name('orders.index');
    // OrderPolicy denies someone else's order as a 404, the same as an unknown number.
    Route::livewire('/orders/{order:order_number}', 'pages::orders.show')
        ->middleware('can:view,order')
        ->name('orders.show');

    // Fortify's default destination (config fortify.home). Sends each role to its own home.
    Route::get('dashboard', function (Request $request) {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return redirect($user->homeUrl());
    })->name('dashboard');

    // Livewire re-applies the role middleware to the board's actions (persistent middleware,
    // AppServiceProvider), and every action also checks OrderPolicy::advance.
    Route::livewire('/barista', 'pages::barista-board')
        ->middleware('role:barista|manager|admin')
        ->name('barista');

    // T3-1: scan a customer's pickup QR with the device camera; the page itself has no actions.
    Route::livewire('/barista/scan', 'pages::barista.scan')
        ->middleware('role:barista|manager|admin')
        ->name('barista.scan');

    // T3-1: where a pickup QR leads. Staff only (a customer gets 403), and only with the
    // QR's relative signature (no host in it, so tea-alley.test and the tunnel both work).
    // Opening it changes nothing; "Confirm pickup" is a Livewire action, which Livewire sends
    // to /livewire/update without the signature: it's protected by the role (persistent
    // middleware), OrderPolicy::advance, and the order having to be Ready.
    Route::livewire('/barista/pickup/{order:order_number}', 'pages::barista.pickup')
        ->middleware(['role:barista|manager|admin', 'signed:relative'])
        ->name('barista.pickup');
});

require __DIR__.'/settings.php';
