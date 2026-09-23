<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

test('eventsテーブルが定義どおりのカラムで作成される', function () {
    expect(Schema::hasColumns('events', [
        'id', 'organizer_id', 'title', 'description', 'location',
        'starts_at', 'capacity', 'image_url', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('Factoryでイベントを作成できる', function () {
    $event = Event::factory()->create();

    expect($event->exists)->toBeTrue()
        ->and($event->starts_at)->toBeInstanceOf(Carbon::class)
        ->and($event->capacity)->toBeInt();
});

test('capacityはnullで作成できる', function () {
    $event = Event::factory()->unlimited()->create();

    expect($event->fresh()->capacity)->toBeNull();
});

test('イベントから主催者を取得できる', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    expect($event->organizer)->toBeInstanceOf(User::class)
        ->and($event->organizer->is($organizer))->toBeTrue();
});

test('ユーザーから主催イベント一覧を取得できる', function () {
    $organizer = User::factory()->create();
    $otherUser = User::factory()->create();
    Event::factory()->count(3)->for($organizer, 'organizer')->create();
    Event::factory()->for($otherUser, 'organizer')->create();

    expect($organizer->organizedEvents)->toHaveCount(3)
        ->each(fn ($event) => $event->organizer_id->toBe($organizer->id));
});

test('主催者のユーザーを削除するとイベントも削除される', function () {
    $event = Event::factory()->create();

    $event->organizer->delete();

    expect(Event::find($event->id))->toBeNull();
});
