<?php

use App\Models\Follow;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

test('followsテーブルが定義どおりのカラムで作成される', function () {
    expect(Schema::hasColumns('follows', ['id', 'follower_id', 'followee_id', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('follows', 'updated_at'))->toBeFalse();
});

test('Factoryでフォローを作成できる', function () {
    $follow = Follow::factory()->create();

    expect($follow->exists)->toBeTrue()
        ->and($follow->follower)->toBeInstanceOf(User::class)
        ->and($follow->followee)->toBeInstanceOf(User::class)
        ->and($follow->created_at)->not->toBeNull();
});

test('同じ組み合わせのフォローは重複して作成できない', function () {
    $follow = Follow::factory()->create();

    expect(fn () => Follow::factory()->create([
        'follower_id' => $follow->follower_id,
        'followee_id' => $follow->followee_id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('ユーザーをフォローするとフォロー一覧・フォロワー一覧に反映される', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $alice->follow($bob);

    expect($alice->isFollowing($bob))->toBeTrue()
        ->and($bob->isFollowing($alice))->toBeFalse()
        ->and($alice->followings->pluck('id')->all())->toBe([$bob->id])
        ->and($bob->followers->pluck('id')->all())->toBe([$alice->id]);
});

test('フォロー済みのユーザーを再度フォローしても1件のまま', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $alice->follow($bob);
    $alice->follow($bob);

    expect(Follow::count())->toBe(1);
});

test('自分自身はフォローできない', function () {
    $alice = User::factory()->create();

    expect(fn () => $alice->follow($alice))->toThrow(InvalidArgumentException::class);
    expect(Follow::count())->toBe(0);
});

test('フォローを解除できる', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $alice->follow($bob);

    $alice->unfollow($bob);

    expect($alice->isFollowing($bob))->toBeFalse()
        ->and(Follow::count())->toBe(0);
});

test('フォローしていないユーザーの解除は何もしない', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $alice->unfollow($bob);

    expect(Follow::count())->toBe(0);
});

test('ユーザーを削除すると関連するフォローも削除される', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $alice->follow($bob);
    $bob->follow($alice);

    $bob->delete();

    expect(Follow::count())->toBe(0);
});
