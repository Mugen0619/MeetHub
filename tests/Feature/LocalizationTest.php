<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;

test('Breezeの画面で使っている英語の文言は、すべてlang/ja.jsonに訳がある', function () {
    $translations = json_decode(File::get(lang_path('ja.json')), true, flags: JSON_THROW_ON_ERROR);

    $keys = collect(File::allFiles(resource_path('views')))
        ->flatMap(function (SplFileInfo $file) {
            preg_match_all("/__\('([^']+)'\)/", File::get($file->getPathname()), $matches);

            return $matches[1];
        })
        // auth.password 等の「ファイル名.キー」形式は lang/ja/*.php で翻訳する
        ->reject(fn (string $key) => preg_match('/^[a-z]+\.[a-z_]+$/', $key) === 1)
        ->unique();

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect($translations)->toHaveKey($key);
    }
});

test('ログイン画面が日本語で表示される', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('メールアドレス')
        ->assertSee('パスワード')
        ->assertSee('ログイン状態を保持する')
        ->assertSee('パスワードをお忘れの方')
        ->assertDontSee('Remember me');
});

test('登録画面が日本語で表示される', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('ユーザー名')
        ->assertSee('表示名')
        ->assertSee('パスワード(確認)')
        ->assertSee('登録する')
        ->assertSee('登録済みの方はこちら');
});

test('プロフィール編集画面(プロフィール情報・パスワード変更・退会)が日本語で表示される', function () {
    $this->actingAs(User::factory()->create())
        ->get('/profile')
        ->assertOk()
        ->assertSee('プロフィール編集')
        ->assertSee('プロフィール情報')
        ->assertSee('パスワードの変更')
        ->assertSee('現在のパスワード')
        ->assertSee('新しいパスワード')
        ->assertSee('退会する')
        ->assertDontSee('Update Password')
        ->assertDontSee('Delete Account');
});

test('ログイン失敗時のメッセージが日本語で表示される', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password')
        ->call('login')
        ->assertHasErrors(['form.email' => 'メールアドレスまたはパスワードが正しくありません。']);
});
