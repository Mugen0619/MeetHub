<?php

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public User $user;

    /**
     * followings: フォロー一覧 / followers: フォロワー一覧(ルート定義で2値に制限済み)
     */
    public string $type;

    public function mount(User $user, string $type): void
    {
        $this->user = $user;
        $this->type = $type;
    }

    /**
     * フォローした(された)日時が新しい順に取得する。
     *
     * @return array{users: LengthAwarePaginator<int, User>}
     */
    public function with(): array
    {
        $relation = $this->type === 'followers'
            ? $this->user->followers()
            : $this->user->followings();

        return [
            'users' => $relation
                ->orderByPivot('created_at', 'desc')
                ->orderBy('users.id')
                ->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <a href="{{ route('users.show', $user) }}" wire:navigate class="text-sm text-gray-600 hover:text-gray-900">
            &larr; {{ $user->display_name }}さんのプロフィールへ戻る
        </a>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg">
                <nav class="flex border-b border-gray-100" aria-label="フォロー一覧・フォロワー一覧の切り替え">
                    @foreach (['followings' => 'フォロー', 'followers' => 'フォロワー'] as $tabType => $label)
                        <a href="{{ route('users.follows', [$user, $tabType]) }}" wire:navigate
                           @if ($type === $tabType) aria-current="page" @endif
                           @class([
                               'flex-1 border-b-2 px-4 py-3 text-center text-sm font-medium',
                               'border-indigo-500 text-indigo-600' => $type === $tabType,
                               'border-transparent text-gray-500 hover:text-gray-700' => $type !== $tabType,
                           ])>
                            {{ $label }}
                        </a>
                    @endforeach
                </nav>

                <h1 class="sr-only">
                    {{ $user->display_name }}さんの{{ $type === 'followers' ? 'フォロワー' : 'フォロー' }}一覧
                </h1>

                @if ($users->isEmpty())
                    <p class="p-6 text-gray-600">
                        {{ $type === 'followers' ? 'フォロワーはいません。' : 'フォローしているユーザーはいません。' }}
                    </p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($users as $listedUser)
                            <li wire:key="user-{{ $listedUser->id }}">
                                <a href="{{ route('users.show', $listedUser) }}" wire:navigate class="flex items-center gap-3 p-4 hover:bg-gray-50">
                                    @if ($listedUser->avatar_url)
                                        <img src="{{ $listedUser->avatar_url }}" alt="" class="h-10 w-10 rounded-full object-cover">
                                    @endif
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $listedUser->display_name }}</p>
                                        <p class="text-sm text-gray-500">{{ '@'.$listedUser->username }}</p>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="mt-6">
                {{ $users->links() }}
            </div>
        </div>
    </div>
</div>
