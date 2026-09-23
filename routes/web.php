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

    Volt::route('users/{user:username}', 'pages.users.show')
        ->name('users.show');

    // フォロー一覧(followings)・フォロワー一覧(followers)は同じ画面をtypeで切り替える
    Volt::route('users/{user:username}/{type}', 'pages.users.follows')
        ->whereIn('type', ['followings', 'followers'])
        ->name('users.follows');
});

require __DIR__.'/auth.php';
