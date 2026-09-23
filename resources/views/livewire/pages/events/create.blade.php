<?php

use App\Livewire\Forms\EventForm;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public EventForm $form;

    /**
     * イベントを作成する。
     */
    public function save(): void
    {
        $this->form->store(Auth::user());

        session()->flash('status', 'イベントを作成しました。');

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">イベント作成</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="save" class="space-y-6">
                    @include('events._form')

                    <div class="flex items-center gap-4">
                        <x-primary-button wire:loading.attr="disabled" wire:target="save,form.image">作成する</x-primary-button>
                        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-gray-600 underline hover:text-gray-900">キャンセル</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
