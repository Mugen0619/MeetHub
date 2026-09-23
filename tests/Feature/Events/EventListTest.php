<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

test('未ログインのユーザーはイベント一覧からログイン画面へリダイレクトされる', function () {
    $this->get(route('events.index'))->assertRedirect(route('login'));
});

test('ログイン済みユーザーはイベント一覧画面を表示できる', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSeeVolt('pages.events.index');
});

test('イベントのタイトル・場所・主催者名が表示される', function () {
    $this->actingAs(User::factory()->create());
    $organizer = User::factory()->create(['display_name' => '主催太郎']);
    Event::factory()->for($organizer, 'organizer')->create([
        'title' => 'Laravelもくもく会',
        'location' => '渋谷',
    ]);

    Volt::test('pages.events.index')
        ->assertSee('Laravelもくもく会')
        ->assertSee('渋谷')
        ->assertSee('主催太郎');
});

test('イベントは開催日時が近い順に表示される', function () {
    $this->actingAs(User::factory()->create());
    Event::factory()->create(['title' => '3番目のイベント', 'starts_at' => now()->addDays(30)]);
    Event::factory()->create(['title' => '1番目のイベント', 'starts_at' => now()->addDay()]);
    Event::factory()->create(['title' => '2番目のイベント', 'starts_at' => now()->addDays(7)]);

    Volt::test('pages.events.index')
        ->assertSeeInOrder(['1番目のイベント', '2番目のイベント', '3番目のイベント']);
});

test('開催日時を過ぎたイベントは一覧に表示されない', function () {
    $this->actingAs(User::factory()->create());
    Event::factory()->past()->create(['title' => '終了したイベント']);
    Event::factory()->create(['title' => '開催予定のイベント']);

    Volt::test('pages.events.index')
        ->assertSee('開催予定のイベント')
        ->assertDontSee('終了したイベント');
});

test('イベントが無い場合はその旨が表示される', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.events.index')
        ->assertSee('開催予定のイベントはありません。');
});

test('各イベントに詳細画面へのリンクが表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    Volt::test('pages.events.index')
        ->assertSee(route('events.show', $event));
});

test('イベントが1ページの件数を超える場合はページ分割される', function () {
    $this->actingAs(User::factory()->create());
    Event::factory()->create(['title' => '13番目のイベント', 'starts_at' => now()->addDays(13)]);
    foreach (range(1, 12) as $i) {
        Event::factory()->create(['title' => "イベント{$i}", 'starts_at' => now()->addHours($i)]);
    }

    Volt::test('pages.events.index')
        ->assertDontSee('13番目のイベント')
        ->call('nextPage')
        ->assertSee('13番目のイベント');
});
