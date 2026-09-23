<?php

use App\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * ログインユーザーが主催するイベント(開催日時の新しい順)。
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function events(): Collection
    {
        return Auth::user()->organizedEvents()->latest('starts_at')->get();
    }

    /**
     * イベントを削除する(終了したイベントは編集できないため、一覧からも削除できるようにする)。
     */
    public function delete(Event $event): void
    {
        $this->authorize('delete', $event);

        $event->delete();

        unset($this->events);
    }
}; ?>

<section>
    <header class="flex items-center justify-between">
        <h2 class="text-lg font-medium text-gray-900">主催イベント</h2>
        <a href="{{ route('events.create') }}" wire:navigate
            class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
            イベント作成
        </a>
    </header>

    @if ($this->events->isEmpty())
        <p class="mt-4 text-sm text-gray-600">まだ主催しているイベントはありません。</p>
    @else
        <ul class="mt-4 divide-y divide-gray-200">
            @foreach ($this->events as $event)
                <li wire:key="event-{{ $event->id }}" class="flex items-center gap-4 py-4">
                    @if ($event->image_url)
                        <img src="{{ $event->image_url }}" alt="" class="h-16 w-24 shrink-0 rounded object-cover">
                    @endif

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate font-medium text-gray-900">{{ $event->title }}</p>
                            <x-event-ended-badge :event="$event" />
                        </div>
                        <p class="text-sm text-gray-600">
                            {{ $event->starts_at->format('Y/m/d H:i') }} ・ {{ $event->location }} ・ 定員{{ $event->capacity }}名
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        @can('update', $event)
                            <a href="{{ route('events.edit', $event) }}" wire:navigate class="text-sm text-indigo-600 underline hover:text-indigo-900">編集</a>
                        @endcan
                        @can('delete', $event)
                            <button type="button" wire:click="delete({{ $event->id }})" wire:confirm="「{{ $event->title }}」を削除します。よろしいですか?"
                                class="text-sm text-red-600 underline hover:text-red-900">削除</button>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
