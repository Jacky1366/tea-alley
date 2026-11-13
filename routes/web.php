<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::menu')->name('home');

// Public on purpose: browsing and building a drink don't need an account; only checkout does.
Route::livewire('/menu/{item}', 'pages::drink')->name('menu.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('barista', 'barista')
        ->middleware('role:barista|manager|admin')
        ->name('barista');
});

require __DIR__.'/settings.php';
