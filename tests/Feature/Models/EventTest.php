<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

test('capacityはnullを許容しない', function () {
    expect(fn () => Event::factory()->create(['capacity' => null]))
        ->toThrow(QueryException::class);
});

test('開催日時を過ぎたイベントは終了扱いになる', function () {
    expect(Event::factory()->past()->make()->isEnded())->toBeTrue()
        ->and(Event::factory()->make()->isEnded())->toBeFalse();
});

test('image_urlは保存パスを現在のディスクの公開URLに変換して返す', function () {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);

    $event = Event::factory()->make(['image_url' => 'events/sample.jpg']);

    expect($event->image_url)->toBe(Storage::url('events/sample.jpg'))
        ->and(Event::factory()->make(['image_url' => 'https://example.com/a.jpg'])->image_url)->toBe('https://example.com/a.jpg')
        ->and(Event::factory()->make(['image_url' => null])->image_url)->toBeNull();
});

test('イベントを削除すると画像ファイルも削除される', function () {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
    Storage::put('events/sample.jpg', 'dummy');
    $event = Event::factory()->create(['image_url' => 'events/sample.jpg']);

    $event->delete();

    Storage::assertMissing('events/sample.jpg');
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
