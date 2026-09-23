<?php

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Volt\Volt;

test('マイ参加予定イベント一覧画面を表示できる', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('events.participating'))
        ->assertOk()
        ->assertSeeVolt('pages.events.participating');
});

test('未ログインではマイ参加予定イベント一覧にアクセスできない', function () {
    $this->get(route('events.participating'))->assertRedirect(route('login'));
});

test('自分が申込みした開催予定のイベントだけが、開催日時が近い順に表示される', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $later = Event::factory()->create(['title' => '来月の勉強会', 'starts_at' => now()->addMonth()]);
    $sooner = Event::factory()->create(['title' => '明日のもくもく会', 'starts_at' => now()->addDay()]);
    $ended = Event::factory()->past()->create(['title' => '終了したイベント']);
    $notJoined = Event::factory()->create(['title' => '申込んでいないイベント']);
    foreach ([$later, $sooner, $ended] as $event) {
        EventParticipation::factory()->for($event)->for($user)->create();
    }
    EventParticipation::factory()->for($notJoined)->create(); // 他人の申込み

    Volt::test('pages.events.participating')
        ->assertSeeInOrder(['明日のもくもく会', '来月の勉強会'])
        ->assertDontSee('終了したイベント')
        ->assertDontSee('申込んでいないイベント');
});

test('申込みがない場合はその旨が表示される', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.events.participating')
        ->assertSee('参加申込みした開催予定のイベントはありません。');
});

test('一覧から参加申込みを取り消せる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['title' => '取消すイベント']);
    EventParticipation::factory()->for($event)->for($user)->create();

    Volt::test('pages.events.participating')
        ->assertSee('取消すイベント')
        ->call('leave', $event->id)
        ->assertHasNoErrors()
        ->assertDontSee('取消すイベント');

    expect($event->isJoinedBy($user))->toBeFalse();
});

test('申込みしていないイベントのIDを指定しても他人の申込みは取り消されない', function () {
    $this->actingAs(User::factory()->create());
    $participation = EventParticipation::factory()->create();

    // Livewireのテストでは例外がHTTPレスポンス(404)に変換されずそのまま送出される
    expect(fn () => Volt::test('pages.events.participating')
        ->call('leave', $participation->event_id)
    )->toThrow(ModelNotFoundException::class);

    expect(EventParticipation::find($participation->id))->not->toBeNull();
});
