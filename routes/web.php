<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware('auth')->group(function () {
    Volt::route('events', 'pages.events.index')
        ->name('events.index');

    Volt::route('events/create', 'pages.events.create')
        ->name('events.create');

    Volt::route('events/{event}/edit', 'pages.events.edit')
        ->name('events.edit');

    // events/create 等の固定パスと衝突しないよう、IDは数値のみに制限する
    Volt::route('events/{event}', 'pages.events.show')
        ->whereNumber('event')
        ->name('events.show');
});

require __DIR__.'/auth.php';
