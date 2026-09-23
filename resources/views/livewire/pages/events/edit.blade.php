<?php

use App\Livewire\Forms\EventForm;
use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public EventForm $form;

    public function mount(Event $event): void
    {
        // Livewireのルートモデルバインディングはミドルウェアより後に解決されるため、can ミドルウェアではなくここで認可する
        $this->authorize('update', $event);

        $this->form->setEvent($event);
    }

    /**
     * イベントを更新する。
     */
    public function save(): void
    {
        // 編集画面を開いたまま開催日時を過ぎた場合なども考慮し、保存時点で改めて認可する
        $this->authorize('update', $this->form->event);

        $this->form->update();

        session()->flash('status', 'イベントを更新しました。');

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * イベントを削除する。
     */
    public function delete(): void
    {
        $this->authorize('delete', $this->form->event);

        $this->form->event->delete();

        session()->flash('status', 'イベントを削除しました。');

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">イベント編集</h2>
    </x-slot>

    <div class="py-12 space-y-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="save" class="space-y-6">
                    @include('events._form', ['currentImageUrl' => $form->event->image_url])

                    <div class="flex items-center gap-4">
                        <x-primary-button wire:loading.attr="disabled" wire:target="save,form.image">更新する</x-primary-button>
                        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-gray-600 underline hover:text-gray-900">キャンセル</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">イベントの削除</h3>
                <p class="mt-1 text-sm text-gray-600">削除したイベントは元に戻せません。</p>
                <x-danger-button type="button" class="mt-4" wire:click="delete" wire:confirm="このイベントを削除します。よろしいですか?">
                    削除する
                </x-danger-button>
            </div>
        </div>
    </div>
</div>
