{{-- イベント作成・編集で共通の入力項目(@include で読み込む。編集時は $currentImageUrl に現在の画像URLを渡す)。親のLivewireコンポーネントは EventForm を $form として持ち、WithFileUploads を使うこと --}}
@php($currentImageUrl ??= null)

<div>
    <x-input-label for="title" value="タイトル" />
    <x-text-input wire:model="form.title" id="title" type="text" class="mt-1 block w-full" required autofocus maxlength="255" />
    <x-input-error class="mt-2" :messages="$errors->get('form.title')" />
</div>

<div>
    <x-input-label for="starts_at" value="開催日時" />
    <x-text-input wire:model="form.starts_at" id="starts_at" type="datetime-local" class="mt-1 block w-full" required />
    <x-input-error class="mt-2" :messages="$errors->get('form.starts_at')" />
</div>

<div>
    <x-input-label for="location" value="開催場所" />
    <x-text-input wire:model="form.location" id="location" type="text" class="mt-1 block w-full" required maxlength="255" placeholder="例: 渋谷区○○ビル 3F / オンライン(Zoom)" />
    <x-input-error class="mt-2" :messages="$errors->get('form.location')" />
</div>

<div>
    <x-input-label for="description" value="説明" />
    <textarea wire:model="form.description" id="description" rows="6" required maxlength="5000"
        class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
    <x-input-error class="mt-2" :messages="$errors->get('form.description')" />
</div>

<div>
    <x-input-label for="capacity" value="定員" />
    <x-text-input wire:model="form.capacity" id="capacity" type="number" min="1" max="10000" step="1" class="mt-1 block w-40" required />
    <x-input-error class="mt-2" :messages="$errors->get('form.capacity')" />
</div>

<div>
    <x-input-label for="image" value="画像(任意・1枚、JPEG/PNG/WebP、5MBまで)" />

    @if ($form->image && $form->image->isPreviewable() && ! $errors->has('form.image'))
        <img src="{{ $form->image->temporaryUrl() }}" alt="選択した画像のプレビュー" class="mt-2 max-h-48 rounded-md">
    @elseif ($currentImageUrl && ! $form->remove_image)
        <img src="{{ $currentImageUrl }}" alt="現在の画像" class="mt-2 max-h-48 rounded-md">
    @endif

    <input wire:model="form.image" id="image" type="file" accept="image/jpeg,image/png,image/webp"
        class="mt-2 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-semibold hover:file:bg-gray-200" />
    <div wire:loading wire:target="form.image" class="mt-1 text-sm text-gray-500">アップロード中...</div>
    <x-input-error class="mt-2" :messages="$errors->get('form.image')" />

    @if ($currentImageUrl)
        <label for="remove_image" class="mt-2 inline-flex items-center">
            <input wire:model.live="form.remove_image" id="remove_image" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
            <span class="ms-2 text-sm text-gray-600">現在の画像を削除する</span>
        </label>
    @endif
</div>
