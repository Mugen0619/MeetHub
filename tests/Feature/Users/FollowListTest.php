<?php

use App\Models\User;
use Livewire\Volt\Volt;

test('未ログインのユーザーはフォロー一覧からログイン画面へリダイレクトされる', function () {
    $user = User::factory()->create();

    $this->get(route('users.follows', [$user, 'followings']))->assertRedirect(route('login'));
});

test('ログイン済みユーザーはフォロー一覧・フォロワー一覧画面を表示できる', function (string $type) {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();

    $this->get(route('users.follows', [$user, $type]))
        ->assertOk()
        ->assertSeeVolt('pages.users.follows');
})->with(['followings', 'followers']);

test('followings・followers以外の種別は404になる', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();

    $this->get('/users/'.$user->username.'/others')->assertNotFound();
});

test('フォロー一覧にはそのユーザーがフォローしているユーザーのみ表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    $user->follow(User::factory()->create(['display_name' => 'フォロー先さん']));
    User::factory()->create(['display_name' => 'フォロワーさん'])->follow($user);

    Volt::test('pages.users.follows', ['user' => $user, 'type' => 'followings'])
        ->assertSee('フォロー先さん')
        ->assertDontSee('フォロワーさん');
});

test('フォロワー一覧にはそのユーザーをフォローしているユーザーのみ表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    $user->follow(User::factory()->create(['display_name' => 'フォロー先さん']));
    User::factory()->create(['display_name' => 'フォロワーさん'])->follow($user);

    Volt::test('pages.users.follows', ['user' => $user, 'type' => 'followers'])
        ->assertSee('フォロワーさん')
        ->assertDontSee('フォロー先さん');
});

test('フォローした日時が新しい順に表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();

    $this->travelTo(now()->subDays(2));
    $user->follow(User::factory()->create(['display_name' => '古いフォロー']));
    $this->travelBack();
    $user->follow(User::factory()->create(['display_name' => '新しいフォロー']));

    Volt::test('pages.users.follows', ['user' => $user, 'type' => 'followings'])
        ->assertSeeInOrder(['新しいフォロー', '古いフォロー']);
});

test('各ユーザーの行にプロフィール画面へのリンクが表示される', function () {
    $this->actingAs(User::factory()->create());
    $user = User::factory()->create();
    $followee = User::factory()->create();
    $user->follow($followee);

    Volt::test('pages.users.follows', ['user' => $user, 'type' => 'followings'])
        ->assertSee(route('users.show', $followee));
});

test('一覧が空の場合はその旨が表示される', function (string $type, string $message) {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.users.follows', ['user' => User::factory()->create(), 'type' => $type])
        ->assertSee($message);
})->with([
    ['followings', 'フォローしているユーザーはいません。'],
    ['followers', 'フォロワーはいません。'],
]);
