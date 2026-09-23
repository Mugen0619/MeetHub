<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public User $user;

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    /**
     * 表示中のユーザーをフォローする(自分自身は不可)。
     */
    public function follow(): void
    {
        $viewer = $this->viewer();

        abort_if($viewer->is($this->user), 403);

        $viewer->follow($this->user);
    }

    /**
     * 表示中のユーザーのフォローを解除する。
     */
    public function unfollow(): void
    {
        $this->viewer()->unfollow($this->user);
    }

    /**
     * 開催予定の主催イベントは、イベント一覧と同様に開催日時が近い順で表示する。
     * 開催終了後のイベントの扱いは要件定義書7節のTBDのため、イベント一覧に合わせて現時点では含めない。
     *
     * @return array{isOwn: bool, isFollowing: bool, followingsCount: int, followersCount: int, events: Collection<int, Event>}
     */
    public function with(): array
    {
        $viewer = $this->viewer();

        return [
            'isOwn' => $viewer->is($this->user),
            'isFollowing' => $viewer->isFollowing($this->user),
            'followingsCount' => $this->user->followings()->count(),
            'followersCount' => $this->user->followers()->count(),
            'events' => $this->user->organizedEvents()
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(),
        ];
    }

    private function viewer(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            プロフィール
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto space-y-6 sm:px-6 lg:px-8">
            <section class="bg-white p-6 shadow-sm sm:rounded-lg">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex items-center gap-4">
                        @if ($user->avatar_url)
                            <img src="{{ $user->avatar_url }}" alt="" class="h-16 w-16 rounded-full object-cover">
                        @endif
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">{{ $user->display_name }}</h1>
                            <p class="text-sm text-gray-500">{{ '@'.$user->username }}</p>
                        </div>
                    </div>

                    @if ($isOwn)
                        <a href="{{ route('profile') }}" wire:navigate
                           class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            プロフィールを編集
                        </a>
                    @elseif ($isFollowing)
                        <x-secondary-button wire:click="unfollow" wire:loading.attr="disabled" aria-pressed="true">
                            フォロー解除
                        </x-secondary-button>
                    @else
                        <x-primary-button wire:click="follow" wire:loading.attr="disabled" aria-pressed="false">
                            フォローする
                        </x-primary-button>
                    @endif
                </div>

                @if ($user->bio)
                    <p class="mt-4 whitespace-pre-line text-gray-700">{{ $user->bio }}</p>
                @endif

                <div class="mt-4 flex gap-6 text-sm">
                    <a href="{{ route('users.follows', [$user, 'followings']) }}" wire:navigate class="text-gray-600 hover:text-gray-900 hover:underline">
                        <span class="font-semibold text-gray-900">{{ $followingsCount }}</span> フォロー
                    </a>
                    <a href="{{ route('users.follows', [$user, 'followers']) }}" wire:navigate class="text-gray-600 hover:text-gray-900 hover:underline">
                        <span class="font-semibold text-gray-900">{{ $followersCount }}</span> フォロワー
                    </a>
                </div>
            </section>

            <section class="bg-white shadow-sm sm:rounded-lg">
                <h2 class="border-b border-gray-100 p-6 text-lg font-semibold text-gray-900">主催イベント</h2>

                @if ($events->isEmpty())
                    <p class="p-6 text-gray-600">開催予定の主催イベントはありません。</p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($events as $event)
                            <li wire:key="event-{{ $event->id }}">
                                <a href="{{ route('events.show', $event) }}" wire:navigate class="block p-6 hover:bg-gray-50">
                                    <p class="text-sm font-medium text-indigo-600">
                                        <time datetime="{{ $event->starts_at->toIso8601String() }}">
                                            {{ $event->starts_at->format('Y/m/d H:i') }}
                                        </time>
                                    </p>
                                    <p class="mt-1 font-semibold text-gray-900">{{ $event->title }}</p>
                                    <p class="text-sm text-gray-600">場所: {{ $event->location }}</p>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
