<?php

use App\Models\Comment;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('commentsテーブルが定義どおりのカラムで作成される', function () {
    expect(Schema::hasColumns('comments', ['id', 'event_id', 'user_id', 'body', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('comments', 'updated_at'))->toBeFalse();
});

test('Factoryでコメントを作成できる', function () {
    $comment = Comment::factory()->create();

    expect($comment->exists)->toBeTrue()
        ->and($comment->body)->toBeString()->not->toBeEmpty()
        ->and($comment->event)->toBeInstanceOf(Event::class)
        ->and($comment->user)->toBeInstanceOf(User::class)
        ->and($comment->created_at)->not->toBeNull();
});

test('イベント・ユーザーからコメントを取得できる', function () {
    $event = Event::factory()->create();
    $user = User::factory()->create();
    Comment::factory()->count(2)->for($event)->for($user)->create();
    Comment::factory()->for($event)->create();

    expect($event->comments)->toHaveCount(3)
        ->and($user->comments)->toHaveCount(2);
});

test('イベントを削除するとコメントも削除される', function () {
    $comment = Comment::factory()->create();

    $comment->event->delete();

    expect(Comment::find($comment->id))->toBeNull();
});

test('投稿者のユーザーを削除するとコメントも削除される', function () {
    $comment = Comment::factory()->create();

    $comment->user->delete();

    expect(Comment::find($comment->id))->toBeNull();
});
