<?php

use App\Exceptions\ParticipationException;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('event_participationsテーブルが定義どおりのカラムで作成される', function () {
    expect(Schema::hasColumns('event_participations', ['id', 'event_id', 'user_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('event_participations', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('event_participations', 'status'))->toBeFalse()
        ->and(Schema::hasColumn('event_participations', 'cancelled_at'))->toBeFalse();
});

test('Factoryで参加申込みを作成できる', function () {
    $participation = EventParticipation::factory()->create();

    expect($participation->exists)->toBeTrue()
        ->and($participation->event)->toBeInstanceOf(Event::class)
        ->and($participation->user)->toBeInstanceOf(User::class)
        ->and($participation->created_at)->not->toBeNull();
});

test('DBのユニーク制約で、同じユーザーが同じイベントに重複して申込みできない', function () {
    $participation = EventParticipation::factory()->create();

    expect(fn () => EventParticipation::factory()->create([
        'event_id' => $participation->event_id,
        'user_id' => $participation->user_id,
    ]))->toThrow(QueryException::class);
});

test('イベントの参加者・ユーザーの参加予定イベントを多対多で取得できる', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    EventParticipation::factory()->for($event)->for($user)->create();
    EventParticipation::factory()->count(2)->for($event)->create();

    expect($event->participants)->toHaveCount(3)
        ->and($event->participants->contains($user))->toBeTrue()
        ->and($user->participatingEvents)->toHaveCount(1)
        ->and($user->participatingEvents->first()->is($event))->toBeTrue();
});

test('参加申込みできる', function () {
    $event = Event::factory()->create(['capacity' => 2]);
    $user = User::factory()->create();

    $participation = $event->join($user);

    expect($participation->user_id)->toBe($user->id)
        ->and($event->isJoinedBy($user))->toBeTrue()
        ->and($event->participations()->count())->toBe(1);
});

test('同じイベントに重複して申込みできない', function () {
    $event = Event::factory()->create(['capacity' => 5]);
    $user = User::factory()->create();
    $event->join($user);

    expect(fn () => $event->join($user))
        ->toThrow(ParticipationException::class, 'このイベントには既に申込み済みです。');

    expect($event->participations()->count())->toBe(1);
});

test('定員に達しているイベントには申込みできない', function () {
    $event = Event::factory()->create(['capacity' => 2]);
    EventParticipation::factory()->count(2)->for($event)->create();

    expect(fn () => $event->join(User::factory()->create()))
        ->toThrow(ParticipationException::class, '定員に達しました。');

    expect($event->participations()->count())->toBe(2);
});

test('定員残り1枠なら申込みでき、その後は定員到達で拒否される', function () {
    $event = Event::factory()->create(['capacity' => 2]);
    EventParticipation::factory()->for($event)->create();

    $event->join(User::factory()->create());

    expect(fn () => $event->join(User::factory()->create()))
        ->toThrow(ParticipationException::class, '定員に達しました。');
    expect($event->participations()->count())->toBe(2);
});

test('主催者は自分のイベントに申込みできない', function () {
    $event = Event::factory()->create();

    expect(fn () => $event->join($event->organizer))
        ->toThrow(ParticipationException::class, '主催者は自分のイベントに参加申込みできません。');

    expect($event->participations()->count())->toBe(0);
});

test('開催日時を過ぎたイベントには申込みできない', function () {
    $event = Event::factory()->past()->create();

    expect(fn () => $event->join(User::factory()->create()))
        ->toThrow(ParticipationException::class, '開催日時を過ぎたイベントには申込みできません。');

    expect($event->participations()->count())->toBe(0);
});

test('取消すと行が削除され、定員に空きができて他のユーザーが申込める', function () {
    $event = Event::factory()->create(['capacity' => 1]);
    $user = User::factory()->create();
    $event->join($user);

    $event->leave($user);

    expect($event->isJoinedBy($user))->toBeFalse()
        ->and(EventParticipation::query()->count())->toBe(0);

    $other = User::factory()->create();
    $event->join($other);
    expect($event->isJoinedBy($other))->toBeTrue();
});

test('取消し後に同じユーザーが再度申込みできる', function () {
    $event = Event::factory()->create(['capacity' => 1]);
    $user = User::factory()->create();
    $event->join($user);
    $event->leave($user);

    $event->join($user);

    expect($event->isJoinedBy($user))->toBeTrue()
        ->and($event->participations()->count())->toBe(1);
});

test('取消しても他のユーザーの申込みは残る', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    $event->join($user);
    $other = EventParticipation::factory()->for($event)->create();

    $event->leave($user);

    expect(EventParticipation::find($other->id))->not->toBeNull();
});

test('開催日時を過ぎたイベントの申込みは取り消せない', function () {
    $event = Event::factory()->past()->create();
    $participation = EventParticipation::factory()->for($event)->create();

    expect(fn () => $event->leave($participation->user))
        ->toThrow(ParticipationException::class, '開催日時を過ぎたイベントの申込みは取り消せません。');

    expect(EventParticipation::find($participation->id))->not->toBeNull();
});

test('イベントを削除すると参加申込みも削除される', function () {
    $participation = EventParticipation::factory()->create();

    $participation->event->delete();

    expect(EventParticipation::find($participation->id))->toBeNull();
});
