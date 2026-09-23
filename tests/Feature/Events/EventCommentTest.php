<?php

use App\Models\Comment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

test('コメントが投稿者名・本文とともに古い順で表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();
    $alice = User::factory()->create(['display_name' => '投稿者アリス']);
    Comment::factory()->for($event)->for($alice)->create([
        'body' => '後のコメント',
        'created_at' => Carbon::parse('2030-01-02 10:00:00'),
    ]);
    Comment::factory()->for($event)->create([
        'body' => '先のコメント',
        'created_at' => Carbon::parse('2030-01-01 10:00:00'),
    ]);
    Comment::factory()->create(['body' => '別イベントのコメント']);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSeeInOrder(['先のコメント', '後のコメント'])
        ->assertSee('投稿者アリス')
        ->assertSee('(2件)')
        ->assertDontSee('別イベントのコメント');
});

test('コメントがない場合はその旨が表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSee('まだコメントはありません。');
});

test('コメントを投稿できる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->set('body', '  参加前に準備するものはありますか?  ')
        ->call('postComment')
        ->assertHasNoErrors()
        ->assertSet('body', '')
        ->assertSee('参加前に準備するものはありますか?');

    $comment = Comment::sole();
    expect($comment->body)->toBe('参加前に準備するものはありますか?')
        ->and($comment->event_id)->toBe($event->id)
        ->and($comment->user_id)->toBe($user->id);
});

test('主催者も自分のイベントにコメントできる', function () {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer);

    Volt::test('pages.events.show', ['event' => $event])
        ->set('body', '当日はよろしくお願いします')
        ->call('postComment')
        ->assertHasNoErrors();

    expect($event->comments()->count())->toBe(1);
});

test('コメント本文は必須', function (string $body) {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->set('body', $body)
        ->call('postComment')
        ->assertHasErrors(['body' => 'required']);

    expect(Comment::count())->toBe(0);
})->with([
    '空文字' => '',
    '空白のみ' => '   ',
]);

test('コメント本文は1000文字まで', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->set('body', str_repeat('あ', 1001))
        ->call('postComment')
        ->assertHasErrors(['body' => 'max']);

    Volt::test('pages.events.show', ['event' => $event])
        ->set('body', str_repeat('あ', 1000))
        ->call('postComment')
        ->assertHasNoErrors();

    expect(Comment::count())->toBe(1);
});

test('コメント本文のHTMLはエスケープして表示される', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();
    Comment::factory()->for($event)->create(['body' => '<script>alert(1)</script>']);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

test('投稿者本人は自分のコメントを削除できる', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();
    $comment = Comment::factory()->for($event)->for($user)->create(['body' => '消すコメント']);

    Volt::test('pages.events.show', ['event' => $event])
        ->assertSeeHtml('wire:click="deleteComment('.$comment->id.')"')
        ->call('deleteComment', $comment->id)
        ->assertDontSee('消すコメント');

    expect(Comment::find($comment->id))->toBeNull();
});

test('他人のコメントには削除ボタンが表示されず、削除もできない', function () {
    $this->actingAs(User::factory()->create());
    $event = Event::factory()->create();
    $comment = Comment::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->assertDontSeeHtml('wire:click="deleteComment('.$comment->id.')"')
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    expect(Comment::find($comment->id))->not->toBeNull();
});

test('イベントの主催者でも他人のコメントは削除できない', function () {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer);
    $comment = Comment::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->call('deleteComment', $comment->id)
        ->assertForbidden();

    expect(Comment::find($comment->id))->not->toBeNull();
});

test('別のイベントのコメントIDを指定しても削除できない', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create();
    $otherEventComment = Comment::factory()->for($user)->create();

    // Livewireのテストでは例外がHTTPレスポンス(404)に変換されずそのまま送出される
    expect(fn () => Volt::test('pages.events.show', ['event' => $event])
        ->call('deleteComment', $otherEventComment->id)
    )->toThrow(ModelNotFoundException::class);

    expect(Comment::find($otherEventComment->id))->not->toBeNull();
});
