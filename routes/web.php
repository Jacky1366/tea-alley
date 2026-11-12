<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::menu')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::view('barista', 'barista')
        ->middleware('role:barista|manager|admin')
        ->name('barista');
});

require __DIR__.'/settings.php';
