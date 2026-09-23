<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

test('ダッシュボードに自分が主催するイベントだけが表示される', function () {
    $user = User::factory()->create();
    Event::factory()->for($user, 'organizer')->create(['title' => '自分のイベント']);
    Event::factory()->create(['title' => '他人のイベント']);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeVolt('events.my-events')
        ->assertSee('自分のイベント')
        ->assertDontSee('他人のイベント');
});

test('終了したイベントには「終了」と表示され、編集リンクは表示されない', function () {
    $user = User::factory()->create();
    $ended = Event::factory()->past()->for($user, 'organizer')->create(['title' => '終わったイベント']);
    $upcoming = Event::factory()->for($user, 'organizer')->create(['title' => 'これからのイベント']);
    $this->actingAs($user);

    Volt::test('events.my-events')
        ->assertSeeInOrder(['これからのイベント', '終わったイベント', '終了'])
        ->assertSee(route('events.edit', $upcoming))
        ->assertDontSee(route('events.edit', $ended));
});

test('主催者は一覧から終了したイベントを削除できる', function () {
    $event = Event::factory()->past()->create();
    $this->actingAs($event->organizer);

    Volt::test('events.my-events')->call('delete', $event->id);

    expect(Event::find($event->id))->toBeNull();
});

test('主催者以外は一覧の削除アクションでイベントを削除できない', function () {
    $event = Event::factory()->create();
    $this->actingAs(User::factory()->create());

    Volt::test('events.my-events')
        ->call('delete', $event->id)
        ->assertForbidden();

    expect(Event::find($event->id))->not->toBeNull();
});
