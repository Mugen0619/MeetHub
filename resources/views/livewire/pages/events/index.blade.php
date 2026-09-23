<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    /**
     * 表示対象のタブ。all: すべてのイベント / following: フォロー中の主催者のイベントのみ
     */
    #[Url]
    public string $tab = 'all';

    /**
     * タブを切り替えたら1ページ目から表示し直す。
     */
    public function updatedTab(): void
    {
        $this->resetPage();
    }

    /**
     * 開催予定のイベントを開催日時が近い順に取得する。
     *
     * 開催終了後のイベントの一覧表示は要件定義書7節のTBDのため、現時点では一覧に含めない。
     *
     * @return array{events: LengthAwarePaginator<int, Event>, isFollowingTab: bool}
     */
    public function with(): array
    {
        // URLで不正な値が渡された場合は「すべて」として扱う
        $isFollowingTab = $this->tab === 'following';

        $user = Auth::user();
        assert($user instanceof User);

        return [
            'isFollowingTab' => $isFollowingTab,
            'events' => Event::query()
                ->with('organizer')
                ->where('starts_at', '>=', now())
                ->when($isFollowingTab, fn ($query) => $query->whereIn(
                    'organizer_id',
                    $user->followings()->select('users.id'),
                ))
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
            <div class="mb-6 flex gap-2" role="group" aria-label="表示するイベントの絞り込み">
                @foreach (['all' => 'すべて', 'following' => 'フォロー中'] as $tabValue => $label)
                    @php($isActive = $isFollowingTab === ($tabValue === 'following'))
                    <button type="button" wire:click="$set('tab', '{{ $tabValue }}')"
                            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                            @class([
                                'rounded-full px-4 py-2 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-indigo-500',
                                'bg-indigo-600 text-white' => $isActive,
                                'bg-white text-gray-700 shadow-sm hover:bg-gray-50' => ! $isActive,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if ($events->isEmpty())
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <p class="p-6 text-gray-600">
                        {{ $isFollowingTab ? 'フォロー中の主催者の開催予定イベントはありません。' : '開催予定のイベントはありません。' }}
                    </p>
                </div>
            @else
                <ul class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($events as $event)
                        <li wire:key="event-{{ $event->id }}"
                            class="flex h-full flex-col overflow-hidden bg-white shadow-sm sm:rounded-lg transition hover:shadow-md">
                            {{-- 主催者名をプロフィールへのリンクにするため、カード全体ではなく本文部分をイベント詳細へのリンクにする(リンクの入れ子を避ける) --}}
                            <a href="{{ route('events.show', $event) }}" wire:navigate
                               class="flex flex-1 flex-col focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500">
                                @if ($event->image_url)
                                    <img src="{{ $event->image_url }}" alt="" class="h-40 w-full object-cover" loading="lazy">
                                @endif

                                <div class="flex flex-1 flex-col gap-2 px-6 pt-6 pb-2">
                                    <p class="text-sm font-medium text-indigo-600">
                                        <time datetime="{{ $event->starts_at->toIso8601String() }}">
                                            {{ $event->starts_at->format('Y/m/d H:i') }}
                                        </time>
                                    </p>
                                    <h3 class="text-lg font-semibold text-gray-900">{{ $event->title }}</h3>
                                    <p class="text-sm text-gray-600">場所: {{ $event->location }}</p>
                                </div>
                            </a>

                            <p class="px-6 pb-6 text-sm text-gray-500">
                                主催:
                                <a href="{{ route('users.show', $event->organizer) }}" wire:navigate class="hover:text-gray-900 hover:underline">
                                    {{ $event->organizer->display_name }}
                                </a>
                            </p>
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
