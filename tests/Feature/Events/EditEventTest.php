<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake('public');
    config(['filesystems.default' => 'public']);
});

test('主催者は編集画面を表示でき、現在の値が入力されている', function () {
    $event = Event::factory()->create(['title' => '元のタイトル', 'capacity' => 10]);
    $this->actingAs($event->organizer);

    $this->get(route('events.edit', $event))
        ->assertOk()
        ->assertSeeVolt('pages.events.edit');

    Volt::test('pages.events.edit', ['event' => $event])
        ->assertSet('form.title', '元のタイトル')
        ->assertSet('form.capacity', 10)
        ->assertSet('form.starts_at', $event->starts_at->format('Y-m-d\TH:i'));
});

test('主催者以外は編集画面にアクセスできない', function () {
    $event = Event::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('events.edit', $event))->assertForbidden();
});

test('未ログインでは編集画面にアクセスできない', function () {
    $event = Event::factory()->create();

    $this->get(route('events.edit', $event))->assertRedirect(route('login'));
});

test('終了したイベントは主催者でも編集できない', function () {
    $event = Event::factory()->past()->create();
    $this->actingAs($event->organizer);

    $this->get(route('events.edit', $event))->assertForbidden();
});

test('主催者はイベントを更新できる', function () {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer);
    $startsAt = now()->addMonth()->startOfMinute();

    Volt::test('pages.events.edit', ['event' => $event])
        ->set('form.title', '新しいタイトル')
        ->set('form.starts_at', $startsAt->format('Y-m-d\TH:i'))
        ->set('form.location', 'オンライン')
        ->set('form.description', '新しい説明')
        ->set('form.capacity', '30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $event->refresh();
    expect($event->title)->toBe('新しいタイトル')
        ->and($event->starts_at->equalTo($startsAt))->toBeTrue()
        ->and($event->location)->toBe('オンライン')
        ->and($event->description)->toBe('新しい説明')
        ->and($event->capacity)->toBe(30);
});

test('更新時も定員のバリデーションが行われる', function () {
    $event = Event::factory()->create(['capacity' => 10]);
    $this->actingAs($event->organizer);

    Volt::test('pages.events.edit', ['event' => $event])
        ->set('form.capacity', '0')
        ->call('save')
        ->assertHasErrors(['form.capacity' => 'min']);

    expect($event->fresh()->capacity)->toBe(10);
});

test('画像を差し替えると古い画像ファイルは削除される', function () {
    Storage::put('events/old.jpg', 'old');
    $event = Event::factory()->create(['image_url' => 'events/old.jpg']);
    $this->actingAs($event->organizer);

    Volt::test('pages.events.edit', ['event' => $event])
        ->set('form.image', UploadedFile::fake()->image('new.png'))
        ->call('save')
        ->assertHasNoErrors();

    $newPath = $event->fresh()->getRawOriginal('image_url');
    expect($newPath)->not->toBe('events/old.jpg');
    Storage::assertExists($newPath);
    Storage::assertMissing('events/old.jpg');
});

test('既存の画像を削除できる', function () {
    Storage::put('events/old.jpg', 'old');
    $event = Event::factory()->create(['image_url' => 'events/old.jpg']);
    $this->actingAs($event->organizer);

    Volt::test('pages.events.edit', ['event' => $event])
        ->set('form.remove_image', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($event->fresh()->image_url)->toBeNull();
    Storage::assertMissing('events/old.jpg');
});

test('主催者は編集画面からイベントを削除できる', function () {
    Storage::put('events/cover.jpg', 'cover');
    $event = Event::factory()->create(['image_url' => 'events/cover.jpg']);
    $this->actingAs($event->organizer);

    Volt::test('pages.events.edit', ['event' => $event])
        ->call('delete')
        ->assertRedirect(route('dashboard'));

    expect(Event::find($event->id))->toBeNull();
    Storage::assertMissing('events/cover.jpg');
});

test('主催者以外は編集コンポーネントを直接呼び出しても更新できない', function () {
    $event = Event::factory()->create(['title' => '元のタイトル']);
    $this->actingAs(User::factory()->create());

    Volt::test('pages.events.edit', ['event' => $event])->assertForbidden();

    expect($event->fresh()->title)->toBe('元のタイトル');
});
