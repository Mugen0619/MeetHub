<?php

use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Event $event;

    public function mount(Event $event): void
    {
        $this->event = $event->load('organizer');
    }
}; ?>

<div>
    <x-slot name="header">
        <a href="{{ route('events.index') }}" wire:navigate class="text-sm text-gray-600 hover:text-gray-900">
            &larr; イベント一覧へ戻る
        </a>
    </x-slot>

    <div class="py-12">
        <article class="max-w-3xl mx-auto overflow-hidden bg-white shadow-sm sm:rounded-lg">
            @if ($event->image_url)
                <img src="{{ $event->image_url }}" alt="{{ $event->title }}のイメージ画像" class="max-h-96 w-full object-cover">
            @endif

            <div class="space-y-6 p-6">
                <div>
                    @if ($event->starts_at->isPast())
                        <span class="inline-block rounded bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">終了</span>
                    @endif
                    <h1 class="mt-1 text-2xl font-bold text-gray-900">{{ $event->title }}</h1>
                </div>

                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt class="font-medium text-gray-500">開催日時</dt>
                    <dd class="text-gray-900">
                        <time datetime="{{ $event->starts_at->toIso8601String() }}">
                            {{ $event->starts_at->format('Y/m/d H:i') }}
                        </time>
                    </dd>

                    <dt class="font-medium text-gray-500">場所</dt>
                    <dd class="text-gray-900">{{ $event->location }}</dd>

                    <dt class="font-medium text-gray-500">主催者</dt>
                    <dd class="text-gray-900">{{ $event->organizer->display_name }}</dd>
                </dl>

                <div>
                    <h2 class="text-sm font-medium text-gray-500">説明</h2>
                    <p class="mt-2 whitespace-pre-line text-gray-900">{{ $event->description }}</p>
                </div>
            </div>
        </article>
    </div>
</div>
