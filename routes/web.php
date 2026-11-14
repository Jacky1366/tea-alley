<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::menu')->name('home');

// Public on purpose: browsing, building a drink, and the cart don't need an account; only checkout does.
Route::livewire('/menu/{item}', 'pages::drink')->name('menu.show');
Route::livewire('/cart', 'pages::cart')->name('cart');

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

    Route::view('barista', 'barista')
        ->middleware('role:barista|manager|admin')
        ->name('barista');
});

require __DIR__.'/settings.php';
