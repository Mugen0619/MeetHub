<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->get('/profile');

    $response
        ->assertOk()
        ->assertSeeVolt('profile.update-profile-information-form')
        ->assertSeeVolt('profile.update-password-form')
        ->assertSeeVolt('profile.delete-user-form');
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.update-profile-information-form')
        ->set('display_name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $component
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $user->refresh();

    $this->assertSame('Test User', $user->display_name);
    $this->assertSame('test@example.com', $user->email);
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $component
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('profile.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $component
        ->assertHasErrors('password')
        ->assertNoRedirect();

    $this->assertNotNull($user->fresh());
});

describe('自己紹介・アイコン', function () {
    beforeEach(function () {
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
    });

    test('自己紹介とアイコンを更新できる', function () {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('bio', "Laravelが好きです。\n勉強会を主催しています。")
            ->set('avatar', UploadedFile::fake()->image('me.png'))
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertDispatched('profile-updated');

        $user->refresh();
        $path = $user->getRawOriginal('avatar_url');

        expect($user->bio)->toBe("Laravelが好きです。\n勉強会を主催しています。")
            ->and($path)->toStartWith('avatars/')
            ->and($user->avatar_url)->toBe(Storage::disk('public')->url($path));
        Storage::disk('public')->assertExists($path);
    });

    test('更新した自己紹介とアイコンがプロフィール画面に表示される', function () {
        $user = User::factory()->create(['username' => 'hanako']);

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('bio', 'PHP・Livewireが好きです。')
            ->set('avatar', UploadedFile::fake()->image('me.png'))
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->actingAs($user)->get('/users/hanako')
            ->assertOk()
            ->assertSee('PHP・Livewireが好きです。')
            ->assertSee($user->fresh()->avatar_url, false);
    });

    test('自己紹介を空にするとnullで保存される', function () {
        $user = User::factory()->create(['bio' => '以前の自己紹介']);

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->assertSet('bio', '以前の自己紹介')
            ->set('bio', '')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        expect($user->fresh()->bio)->toBeNull();
    });

    test('自己紹介は1000文字まで', function () {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('bio', str_repeat('あ', 1001))
            ->call('updateProfileInformation')
            ->assertHasErrors(['bio' => 'max']);

        expect($user->fresh()->bio)->toBeNull();
    });

    test('アイコンを差し替えると古い画像ファイルは削除される', function () {
        Storage::disk('public')->put('avatars/old.png', 'old');
        $user = User::factory()->create(['avatar_url' => 'avatars/old.png']);

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('avatar', UploadedFile::fake()->image('new.png'))
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $path = $user->fresh()->getRawOriginal('avatar_url');

        expect($path)->not->toBe('avatars/old.png');
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing('avatars/old.png');
    });

    test('現在のアイコンを削除できる', function () {
        Storage::disk('public')->put('avatars/old.png', 'old');
        $user = User::factory()->create(['avatar_url' => 'avatars/old.png']);

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('remove_avatar', true)
            ->call('updateProfileInformation')
            ->assertHasNoErrors()
            ->assertSet('currentAvatarUrl', null);

        expect($user->fresh()->avatar_url)->toBeNull();
        Storage::disk('public')->assertMissing('avatars/old.png');
    });

    test('アイコンを指定しなければ現在のアイコンは変わらない', function () {
        Storage::disk('public')->put('avatars/old.png', 'old');
        $user = User::factory()->create(['avatar_url' => 'avatars/old.png']);

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('bio', '自己紹介だけ変更')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        expect($user->fresh()->getRawOriginal('avatar_url'))->toBe('avatars/old.png');
        Storage::disk('public')->assertExists('avatars/old.png');
    });

    test('アイコンは画像(JPEG/PNG/WebP)のみ', function () {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('avatar', UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'))
            ->call('updateProfileInformation')
            ->assertHasErrors('avatar');

        expect($user->fresh()->avatar_url)->toBeNull();
    });

    test('アイコンは5MBまで', function () {
        $user = User::factory()->create();

        Volt::actingAs($user)->test('profile.update-profile-information-form')
            ->set('avatar', UploadedFile::fake()->image('large.png')->size(5121))
            ->call('updateProfileInformation')
            ->assertHasErrors(['avatar' => 'max']);

        expect($user->fresh()->avatar_url)->toBeNull();
    });

    test('退会するとアイコンの画像ファイルも削除される', function () {
        Storage::disk('public')->put('avatars/me.png', 'me');
        $user = User::factory()->create(['avatar_url' => 'avatars/me.png']);

        Volt::actingAs($user)->test('profile.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        expect($user->fresh())->toBeNull();
        Storage::disk('public')->assertMissing('avatars/me.png');
    });
});
