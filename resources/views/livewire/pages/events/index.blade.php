<?php

use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    /**
     * 開催予定のイベントを開催日時が近い順に取得する。
     *
     * 開催終了後のイベントの一覧表示は要件定義書7節のTBDのため、現時点では一覧に含めない。
     *
     * @return array{events: LengthAwarePaginator<int, Event>}
     */
    public function with(): array
    {
        return [
            'events' => Event::query()
                ->with('organizer')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->paginate(12),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            イベント一覧
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if ($events->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <p class="p-6 text-gray-600">開催予定のイベントはありません。</p>
                </div>
            @else
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($events as $event)
                        <li wire:key="event-{{ $event->id }}">
                            <a href="{{ route('events.show', $event) }}" wire:navigate
                               class="flex h-full flex-col overflow-hidden bg-white shadow-sm sm:rounded-lg transition hover:shadow-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                @if ($event->image_url)
                                    <img src="{{ $event->image_url }}" alt="" class="h-40 w-full object-cover" loading="lazy">
                                @endif

                                <div class="flex flex-1 flex-col gap-2 p-6">
                                    <p class="text-sm font-medium text-indigo-600">
                                        <time datetime="{{ $event->starts_at->toIso8601String() }}">
                                            {{ $event->starts_at->format('Y/m/d H:i') }}
                                        </time>
                                    </p>
                                    <h3 class="text-lg font-semibold text-gray-900">{{ $event->title }}</h3>
                                    <p class="text-sm text-gray-600">場所: {{ $event->location }}</p>
                                    <p class="mt-auto text-sm text-gray-500">主催: {{ $event->organizer->display_name }}</p>
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="mt-6">
                    {{ $events->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
