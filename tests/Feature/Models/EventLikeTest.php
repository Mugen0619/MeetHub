<?php

use App\Models\Event;
use App\Models\EventLike;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('event_likesテーブルが定義どおりのカラムで作成される', function () {
    expect(Schema::hasColumns('event_likes', ['id', 'event_id', 'user_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('event_likes', 'updated_at'))->toBeFalse();
});

test('Factoryで「興味あり」を作成できる', function () {
    $like = EventLike::factory()->create();

    expect($like->exists)->toBeTrue()
        ->and($like->event)->toBeInstanceOf(Event::class)
        ->and($like->user)->toBeInstanceOf(User::class)
        ->and($like->created_at)->not->toBeNull();
});

test('同じユーザーが同じイベントに重複して「興味あり」できない', function () {
    $like = EventLike::factory()->create();

    expect(fn () => EventLike::factory()->create([
        'event_id' => $like->event_id,
        'user_id' => $like->user_id,
    ]))->toThrow(QueryException::class);
});

test('イベント・ユーザーから「興味あり」を取得できる', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventLike::factory()->for($event)->for($user)->create();
    EventLike::factory()->count(2)->for($event)->create();

    expect($event->likes)->toHaveCount(3)
        ->and($user->eventLikes)->toHaveCount(1);
});

test('イベントを削除すると「興味あり」も削除される', function () {
    $like = EventLike::factory()->create();

    $like->event->delete();

    expect(EventLike::find($like->id))->toBeNull();
});

test('ユーザーを削除するとそのユーザーの「興味あり」も削除される', function () {
    $like = EventLike::factory()->create();

    $like->user->delete();

    expect(EventLike::find($like->id))->toBeNull();
});
