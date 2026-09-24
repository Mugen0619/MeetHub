<?php

use App\Models\User;

test('未ログインでトップページを開くと、MeetHubの紹介とログイン・登録への導線が表示される', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<h1', false)
        ->assertSee('MeetHub')
        ->assertSee('勉強会・もくもく会などのイベントを告知し、参加者を集めるためのイベント掲示板です。')
        ->assertSee('href="'.route('login').'"', false)
        ->assertSee('href="'.route('register').'"', false)
        ->assertDontSee('イベント一覧へ')
        // Laravelのデフォルトのウェルカム画面ではないこと
        ->assertDontSee('Laracasts');
});

test('ログイン済みでトップページを開くと、イベント一覧への導線が表示される', function () {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('イベント一覧へ')
        ->assertSee('href="'.route('events.index').'"', false)
        ->assertDontSee('href="'.route('register').'"', false);
});
