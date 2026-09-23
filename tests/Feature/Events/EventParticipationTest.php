<?php

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Livewire\Volt\Volt;

test('イベント詳細に現在の参加人数と定員が表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create(['capacity' => 10]);
    EventParticipation::factory()->count(3)->for($event)->create();
    EventParticipation::factory()->create(); // 別イベントの申込みは数えない

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('participantsCount', 3)
        ->assertSeeHtml('data-testid="participants-count">3</span>')
        ->assertSee('定員10名');
});

test('詳細画面から参加申込みでき、取消しボタンに切り替わる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['capacity' => 5]);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('参加申込みする')
        ->call('join')
        ->assertHasNoErrors()
        ->assertSet('isJoined', true)
        ->assertSet('participantsCount', 1)
        ->assertSee('参加申込み済みです')
        ->assertSee('参加を取り消す');

    expect($event->isJoinedBy($user))->toBeTrue();
});

test('詳細画面から取消しでき、再度申込みできる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['capacity' => 1]);
    EventParticipation::factory()->for($event)->for($user)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('isJoined', true)
        ->call('leave')
        ->assertSet('isJoined', false)
        ->assertSet('participantsCount', 0)
        ->assertSee('参加申込みする')
        ->call('join')
        ->assertHasNoErrors()
        ->assertSet('isJoined', true)
        ->assertSet('participantsCount', 1);
});

test('定員に達している場合は申込みボタンが無効化され「定員に達しました」と表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create(['capacity' => 1]);
    EventParticipation::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('定員に達しました')
        ->assertDontSeeHtml('wire:click="join"');
});

test('画面表示後に定員に達した場合、申込みは拒否され「定員に達しました」と表示される', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['capacity' => 1]);

    $component = Volt::test('pages.events.show', ['event' => $event]);
    EventParticipation::factory()->for($event)->create(); // 他のユーザーが先に申込んだ

    $component->call('join')
        ->assertHasErrors(['participation'])
        ->assertSee('定員に達しました。')
        ->assertSet('participantsCount', 1);

    expect($event->isJoinedBy($user))->toBeFalse();
});

test('申込み済みのユーザーが重複して申込んでも1件のままになる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();
    EventParticipation::factory()->for($event)->for($user)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->call('join')
        ->assertHasErrors(['participation'])
        ->assertSee('このイベントには既に申込み済みです。');

    expect($event->participations()->count())->toBe(1);
});

test('主催者には申込みボタンが表示されず、直接呼び出しても申込みできない', function () {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('主催者は参加申込みの対象外です')
        ->assertDontSee('参加申込みする')
        ->call('join')
        ->assertHasErrors(['participation']);

    expect($event->participations()->count())->toBe(0);
});

test('終了したイベントには申込みボタンが表示されず、直接呼び出しても申込みできない', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->past()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('申込みを受け付けていません')
        ->assertDontSee('参加申込みする')
        ->call('join')
        ->assertHasErrors(['participation'])
        ->assertSee('開催日時を過ぎたイベントには申込みできません。');

    expect($event->participations()->count())->toBe(0);
});

test('終了したイベントの申込みは詳細画面から取り消せない', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->past()->create();
    EventParticipation::factory()->for($event)->for($user)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSee('参加を取り消す')
        ->call('leave')
        ->assertHasErrors(['participation']);

    expect($event->isJoinedBy($user))->toBeTrue();
});

test('主催者には参加者一覧が表示される', function () {
    $event = Event::factory()->create();
    $alice = User::factory()->create(['display_name' => '参加者アリス']);
    $bob = User::factory()->create(['display_name' => '参加者ボブ']);
    EventParticipation::factory()->for($event)->for($alice)->create();
    EventParticipation::factory()->for($event)->for($bob)->create();
    $this->actingAs($event->organizer);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('参加者一覧')
        ->assertSeeInOrder(['参加者アリス', '参加者ボブ']);
});

test('参加者本人を含め、主催者以外には参加者一覧が表示されない', function () {
    $event = Event::factory()->create();
    $participant = User::factory()->create(['display_name' => '参加者アリス']);
    EventParticipation::factory()->for($event)->for($participant)->create();
    EventParticipation::factory()->for($event)->create(['user_id' => User::factory()->create(['display_name' => '参加者ボブ'])]);

    foreach ([$participant, User::factory()->create()] as $viewer) {
        $this->actingAs($viewer);

        Volt::test('pages.events.show', ['event' => $event])
            ->assertDontSee('参加者一覧')
            ->assertDontSee('参加者ボブ');
    }
});
