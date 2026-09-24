<?php

use App\Exceptions\ParticipationException;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /**
     * ログインユーザーが参加申込みした、開催予定のイベント(開催日時が近い順)。
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function events(): Collection
    {
        return $this->currentUser()->participatingEvents()
            ->with('organizer')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->orderBy('events.id')
            ->get();
    }

    /**
     * 参加申込みを取り消す(開催日時より前まで)。
     */
    public function leave(int $eventId): void
    {
        // 申込みしていないイベントのIDを指定されても何も起きないよう、自分の申込み先に限定して探す
        $event = $this->currentUser()->participatingEvents()->findOrFail($eventId);

        try {
            $event->leave($this->currentUser());
        } catch (ParticipationException $e) {
            $this->addError('participation', $e->getMessage());
        }

        unset($this->events);
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="font-semibold text-xl text-gray-800 leading-tight">
            参加予定のイベント
        </h1>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                <x-input-error :messages="$errors->get('participation')" class="mb-4" />

                @if ($this->events->isEmpty())
                    <p class="text-sm text-gray-600">参加申込みした開催予定のイベントはありません。</p>
                    <a href="{{ route('events.index') }}" wire:navigate class="mt-2 inline-block text-sm text-indigo-600 underline hover:text-indigo-900">
                        イベント一覧を見る
                    </a>
                @else
                    <ul class="divide-y divide-gray-200">
                        @foreach ($this->events as $event)
                            <li wire:key="event-{{ $event->id }}" class="flex items-center gap-4 py-4">
                                <a href="{{ route('events.show', $event) }}" wire:navigate class="min-w-0 flex-1 hover:underline">
                                    <p class="text-sm font-medium text-indigo-600">
                                        <time datetime="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('Y/m/d H:i') }}</time>
                                    </p>
                                    <p class="truncate font-medium text-gray-900">{{ $event->title }}</p>
                                    <p class="text-sm text-gray-600">{{ $event->location }} ・ 主催: {{ $event->organizer->display_name }}</p>
                                </a>

                                <button
                                    type="button"
                                    wire:click="leave({{ $event->id }})"
                                    wire:confirm="「{{ $event->title }}」への参加申込みを取り消します。よろしいですか?"
                                    class="shrink-0 text-sm text-red-600 underline hover:text-red-900"
                                >
                                    取消し
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
