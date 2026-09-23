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
    Volt::route('events/create', 'pages.events.create')
        ->name('events.create');

    Volt::route('events/{event}/edit', 'pages.events.edit')
        ->name('events.edit');
});

require __DIR__.'/auth.php';
