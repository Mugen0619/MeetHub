<?php

use App\Models\Event;
use App\Models\EventLike;
use App\Models\User;
use Livewire\Volt\Volt;

test('「興味あり」の件数がイベント詳細に表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();
    EventLike::factory()->count(3)->for($event)->create();
    EventLike::factory()->create(); // 別イベントの「興味あり」は数えない

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('likesCount', 3)
        ->assertSeeHtml('data-testid="likes-count">3</span>');
});

test('「興味あり」を押すと登録され、件数が増える', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('isLiked', false)
        ->call('toggleLike')
        ->assertSet('isLiked', true)
        ->assertSet('likesCount', 1)
        ->assertSeeHtml('aria-pressed="true"');

    expect($event->likes()->where('user_id', $user->id)->exists())->toBeTrue();
});

test('「興味あり」済みで再度押すと解除され、件数が減る', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();
    EventLike::factory()->for($event)->for($user)->create();
    EventLike::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('isLiked', true)
        ->call('toggleLike')
        ->assertSet('isLiked', false)
        ->assertSet('likesCount', 1)
        ->assertSeeHtml('aria-pressed="false"');

    expect($event->likes()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('解除しても他のユーザーの「興味あり」は残る', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();
    EventLike::factory()->for($event)->for($user)->create();
    $otherLike = EventLike::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])->call('toggleLike');

    expect(EventLike::find($otherLike->id))->not->toBeNull();
});

test('主催者自身も自分のイベントに「興味あり」できる', function () {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer);

    Volt::test('pages.events.show', ['event' => $event])
        ->call('toggleLike')
        ->assertSet('isLiked', true)
        ->assertSet('likesCount', 1);
});

test('他のユーザーが「興味あり」していても、自分は未登録として表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();
    EventLike::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSet('isLiked', false)
        ->assertSeeHtml('aria-pressed="false"');
});
