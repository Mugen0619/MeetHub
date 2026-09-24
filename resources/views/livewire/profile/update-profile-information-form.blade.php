<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $display_name = '';
    public string $email = '';
    public string $bio = '';

    /** @var TemporaryUploadedFile|null */
    public $avatar = null;

    /** 現在のアイコンを削除するか */
    public bool $remove_avatar = false;

    /** 現在のアイコンの公開URL(表示用) */
    public ?string $currentAvatarUrl = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $user = Auth::user();

        $this->display_name = $user->display_name;
        $this->email = $user->email;
        $this->bio = $user->bio ?? '';
        $this->currentAvatarUrl = $user->avatar_url;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'bio' => ['nullable', 'string', 'max:1000'],
            // イベント画像と同じ条件(任意・1枚、JPEG/PNG/WebP、5MBまで)
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_avatar' => ['boolean'],
        ], [
            'bio.max' => '自己紹介は:max文字以内で入力してください。',
            'avatar.image' => 'アイコンには画像ファイルを指定してください。',
            'avatar.mimes' => 'アイコンはJPEG・PNG・WebP形式のファイルを指定してください。',
            'avatar.max' => 'アイコンは:maxKB以下のファイルを指定してください。',
        ]);

        $user->fill([
            'display_name' => $validated['display_name'],
            'email' => $validated['email'],
            'bio' => $validated['bio'] === '' ? null : $validated['bio'],
        ]);

        $replacesAvatar = $this->avatar || $this->remove_avatar;
        $previous = clone $user;

        if ($replacesAvatar) {
            $user->avatar_url = $this->avatar ? $this->storeAvatar() : null;
        }

        $user->save();

        if ($replacesAvatar) {
            // 保存に成功してから、差し替え・削除前の古い画像ファイルを削除する
            $previous->deleteAvatarFile();
        }

        $this->reset('avatar', 'remove_avatar');
        $this->currentAvatarUrl = $user->avatar_url;

        $this->dispatch('profile-updated', name: $user->display_name);
    }

    /**
     * アップロードされた画像を、現在のディスク(FILESYSTEM_DISK)の avatars/ 配下に保存し、保存パスを返す。
     * イベント画像(EventForm::storeImage)と同じく、S3の場合はブラウザから直接アップロード済みの一時ファイルをS3内でコピーする。
     */
    private function storeAvatar(): string
    {
        $path = $this->avatar->store('avatars', config('filesystems.default'));

        if ($path === false) {
            throw new \RuntimeException('アイコン画像の保存に失敗しました。');
        }

        return $path;
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            表示名・自己紹介・アイコン・メールアドレスを変更できます。
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-6 space-y-6">
        <div>
            <x-input-label for="display_name" :value="__('Name')" />
            <x-text-input wire:model="display_name" id="display_name" name="display_name" type="text" class="mt-1 block w-full" required autofocus autocomplete="name" />
            <x-input-error class="mt-2" :messages="$errors->get('display_name')" />
        </div>

        <div>
            <x-input-label for="bio" value="自己紹介(任意・1000文字まで)" />
            <textarea wire:model="bio" id="bio" name="bio" rows="4"
                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
            <x-input-error class="mt-2" :messages="$errors->get('bio')" />
        </div>

        <div>
            <x-input-label for="avatar" value="アイコン(任意・1枚、JPEG/PNG/WebP、5MBまで)" />

            @if ($avatar && $avatar->isPreviewable() && ! $errors->has('avatar'))
                <img src="{{ $avatar->temporaryUrl() }}" alt="選択したアイコンのプレビュー" class="mt-2 h-20 w-20 rounded-full object-cover">
            @elseif ($currentAvatarUrl && ! $remove_avatar)
                <img src="{{ $currentAvatarUrl }}" alt="現在のアイコン" class="mt-2 h-20 w-20 rounded-full object-cover">
            @endif

            <input wire:model="avatar" id="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                class="mt-2 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-semibold hover:file:bg-gray-200" />
            <div wire:loading wire:target="avatar" class="mt-1 text-sm text-gray-500">アップロード中...</div>
            <x-input-error class="mt-2" :messages="$errors->get('avatar')" />

            @if ($currentAvatarUrl)
                <label for="remove_avatar" class="mt-2 inline-flex items-center">
                    <input wire:model.live="remove_avatar" id="remove_avatar" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                    <span class="ms-2 text-sm text-gray-600">現在のアイコンを削除する</span>
                </label>
            @endif
        </div>

        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" name="email" type="email" class="mt-1 block w-full" required autocomplete="username" />
            <x-input-error class="mt-2" :messages="$errors->get('email')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button wire:loading.attr="disabled" wire:target="updateProfileInformation,avatar">{{ __('Save') }}</x-primary-button>

            <x-action-message class="me-3" on="profile-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>
</section>
