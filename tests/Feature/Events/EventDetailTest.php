<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

test('未ログインのユーザーはイベント詳細からログイン画面へリダイレクトされる', function () {
    $event = Event::factory()->create();

    $this->get(route('events.show', $event))->assertRedirect(route('login'));
});

test('ログイン済みユーザーはイベント詳細画面を表示できる', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSeeVolt('pages.events.show');
});

test('タイトル・日時・場所・説明・画像・主催者が表示される', function () {
    $this->actingAs(User::factory()->create());
    $organizer = User::factory()->create(['display_name' => '主催花子']);
    $event = Event::factory()->for($organizer, 'organizer')->create([
        'title' => 'Livewire勉強会',
        'starts_at' => '2030-04-01 19:30:00',
        'location' => 'オンライン',
        'description' => '第1部: 入門'."\n".'第2部: 実践',
        'image_url' => 'https://example.com/images/livewire.png',
    ]);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('Livewire勉強会')
        ->assertSee('2030/04/01 19:30')
        ->assertSee('オンライン')
        ->assertSee('第1部: 入門')
        ->assertSee('第2部: 実践')
        ->assertSee('https://example.com/images/livewire.png')
        ->assertSee('主催花子');
});

test('画像が未設定のイベントでは画像を表示しない', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create(['image_url' => null]);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSeeHtml('<img');
});

test('開催日時を過ぎたイベントには終了表示が出る', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->past()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('終了');
});

test('開催予定のイベントには終了表示が出ない', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSee('終了');
});

test('存在しないイベントの詳細は404になる', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/events/999999')->assertNotFound();
});

test('イベント説明のHTMLはエスケープして表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create(['description' => '<script>alert(1)</script>']);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});
