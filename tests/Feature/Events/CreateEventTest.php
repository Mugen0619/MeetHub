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

/**
 * @return array<string, mixed>
 */
function validEventInput(): array
{
    return [
        'form.title' => 'Laravelもくもく会',
        'form.starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'form.location' => '渋谷',
        'form.description' => 'みんなでもくもく作業します。',
        'form.capacity' => '20',
    ];
}

function fillEventForm($component, array $input)
{
    foreach ($input as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

test('未ログインではイベント作成画面にアクセスできない', function () {
    $this->get(route('events.create'))->assertRedirect(route('login'));
});

test('ログインユーザーはイベント作成画面を表示できる', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('events.create'))
        ->assertOk()
        ->assertSeeVolt('pages.events.create');
});

test('画像付きでイベントを作成できる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.image', UploadedFile::fake()->image('cover.jpg'))
        ->call('save');

    $component->assertHasNoErrors()->assertRedirect(route('dashboard'));

    $event = Event::sole();
    expect($event->organizer->is($user))->toBeTrue()
        ->and($event->title)->toBe('Laravelもくもく会')
        ->and($event->location)->toBe('渋谷')
        ->and($event->capacity)->toBe(20)
        ->and($event->getRawOriginal('image_url'))->toStartWith('events/');

    Storage::disk('public')->assertExists($event->getRawOriginal('image_url'));
});

test('画像なしでもイベントを作成できる', function () {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->call('save')
        ->assertHasNoErrors();

    expect(Event::sole()->image_url)->toBeNull();
});

test('定員は1以上の整数でなければならない', function (mixed $capacity) {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.capacity', $capacity)
        ->call('save')
        ->assertHasErrors(['form.capacity']);

    expect(Event::count())->toBe(0);
})->with([
    '未入力(無制限は許容しない)' => '',
    '0' => '0',
    '負の数' => '-1',
    '小数' => '1.5',
    '文字列' => 'abc',
    '上限超過' => '10001',
]);

test('定員は1で作成できる', function () {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.capacity', '1')
        ->call('save')
        ->assertHasNoErrors();

    expect(Event::sole()->capacity)->toBe(1);
});

test('必須項目が未入力だと作成できない', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.events.create')
        ->call('save')
        ->assertHasErrors([
            'form.title' => 'required',
            'form.starts_at' => 'required',
            'form.location' => 'required',
            'form.description' => 'required',
            'form.capacity' => 'required',
        ]);
});

test('開催日時に過去の日時は指定できない', function () {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.starts_at', now()->subHour()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertHasErrors(['form.starts_at' => 'after']);
});

test('画像以外のファイルはアップロードできない', function () {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.image', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
        ->call('save')
        ->assertHasErrors(['form.image']);

    expect(Event::count())->toBe(0);
});

test('5MBを超える画像はアップロードできない', function () {
    $this->actingAs(User::factory()->create());

    fillEventForm(Volt::test('pages.events.create'), validEventInput())
        ->set('form.image', UploadedFile::fake()->image('large.jpg')->size(5121))
        ->call('save')
        ->assertHasErrors(['form.image' => 'max']);
});
