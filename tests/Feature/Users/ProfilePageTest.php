<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

test('未ログインのユーザーはプロフィール画面からログイン画面へリダイレクトされる', function () {
    $user = User::factory()->create();

    $this->get(route('users.show', $user))->assertRedirect(route('login'));
});

test('プロフィール画面のURLはユーザー名で指定する', function () {
    $user = User::factory()->create(['username' => 'taro']);

    expect(route('users.show', $user))->toEndWith('/users/taro');
});

test('ログイン済みユーザーは他人のプロフィール画面を表示できる', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create([
        'username' => 'taro',
        'display_name' => '主催太郎',
        'bio' => 'Laravel勉強中です',
    ]);

    $this->get(route('users.show', $user))
        ->assertOk()
        ->assertSeeVolt('pages.users.show')
        ->assertSee('主催太郎')
        ->assertSee('@taro')
        ->assertSee('Laravel勉強中です');
});

test('存在しないユーザー名のプロフィール画面は404になる', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/users/not-exists')->assertNotFound();
});

test('他人のプロフィール画面にはフォローボタンが表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();

    Volt::test('pages.users.show', ['user' => $user])
        ->assertSee('フォローする')
        ->assertDontSee('フォロー解除')
        ->assertDontSee('プロフィールを編集');
});

test('自分のプロフィール画面にはフォローボタンが表示されず編集リンクが表示される', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    Volt::test('pages.users.show', ['user' => $me])
        ->assertDontSee('フォローする')
        ->assertDontSee('フォロー解除')
        ->assertSee('プロフィールを編集');
});

test('フォローボタンでフォローでき、フォロー解除ボタンに切り替わる', function () {
    $me = User::factory()->create();
    $user = User::factory()->create();
    $this->actingAs($me);

    Volt::test('pages.users.show', ['user' => $user])
        ->call('follow')
        ->assertSee('フォロー解除')
        ->assertDontSee('フォローする');

    expect($me->isFollowing($user))->toBeTrue();
});

test('フォロー解除ボタンでフォローを解除できる', function () {
    $me = User::factory()->create();
    $user = User::factory()->create();
    $me->follow($user);
    $this->actingAs($me);

    Volt::test('pages.users.show', ['user' => $user])
        ->assertSee('フォロー解除')
        ->call('unfollow')
        ->assertSee('フォローする');

    expect($me->isFollowing($user))->toBeFalse();
});

test('自分自身をフォローしようとすると403になる', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    Volt::test('pages.users.show', ['user' => $me])
        ->call('follow')
        ->assertForbidden();

    expect($me->followings()->count())->toBe(0);
});

test('フォロー数・フォロワー数と一覧へのリンクが表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    $user->follow(User::factory()->create());
    User::factory()->count(2)->create()->each->follow($user);

    Volt::test('pages.users.show', ['user' => $user])
        ->assertSeeHtml('<span class="font-semibold text-gray-900">1</span> フォロー')
        ->assertSeeHtml('<span class="font-semibold text-gray-900">2</span> フォロワー')
        ->assertSee(route('users.follows', [$user, 'followings']))
        ->assertSee(route('users.follows', [$user, 'followers']));
});

test('そのユーザーが主催する開催予定のイベントが開催日時が近い順に表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    Event::factory()->for($user, 'organizer')->create(['title' => '後のイベント', 'starts_at' => now()->addDays(10)]);
    Event::factory()->for($user, 'organizer')->create(['title' => '先のイベント', 'starts_at' => now()->addDay()]);
    Event::factory()->for($user, 'organizer')->past()->create(['title' => '終了したイベント']);
    Event::factory()->create(['title' => '他人のイベント']);

    Volt::test('pages.users.show', ['user' => $user])
        ->assertSeeInOrder(['先のイベント', '後のイベント'])
        ->assertDontSee('終了したイベント')
        ->assertDontSee('他人のイベント');
});

test('主催イベントが無い場合はその旨が表示される', function () {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.users.show', ['user' => User::factory()->create()])
        ->assertSee('開催予定の主催イベントはありません。');
});
