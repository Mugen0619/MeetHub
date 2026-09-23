<?php

use App\Exceptions\ParticipationException;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Event $event;

    #[Validate('required|string|max:1000', as: 'コメント')]
    public string $body = '';

    public function mount(Event $event): void
    {
        $this->event = $event->load('organizer');
    }

    /**
     * 「興味あり」の件数。
     */
    #[Computed]
    public function likesCount(): int
    {
        return $this->event->likes()->count();
    }

    /**
     * ログインユーザーが「興味あり」済みかどうか。
     */
    #[Computed]
    public function isLiked(): bool
    {
        return $this->event->likes()->where('user_id', Auth::id())->exists();
    }

    /**
     * 現在の参加人数。
     */
    #[Computed]
    public function participantsCount(): int
    {
        return $this->event->participations()->count();
    }

    /**
     * ログインユーザーが参加申込み済みかどうか。
     */
    #[Computed]
    public function isJoined(): bool
    {
        return $this->event->isJoinedBy($this->currentUser());
    }

    /**
     * 参加者一覧(申込みの古い順)。主催者本人のみ閲覧できる(要件定義書4.6節)。
     *
     * @return Collection<int, EventParticipation>
     */
    #[Computed]
    public function participations(): Collection
    {
        $this->authorize('viewParticipants', $this->event);

        return $this->event->participations()->with('user')->oldest()->oldest('id')->get();
    }

    /**
     * 参加申込みする。定員・重複・主催者本人・開催日時超過のチェックはEvent::joinで行う。
     */
    public function join(): void
    {
        try {
            $this->event->join($this->currentUser());
        } catch (ParticipationException $e) {
            $this->addError('participation', $e->getMessage());
        }

        unset($this->participantsCount, $this->isJoined);
    }

    /**
     * 参加申込みを取り消す(開催日時より前まで)。
     */
    public function leave(): void
    {
        try {
            $this->event->leave($this->currentUser());
        } catch (ParticipationException $e) {
            $this->addError('participation', $e->getMessage());
        }

        unset($this->participantsCount, $this->isJoined);
    }

    private function currentUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * コメント一覧(投稿の古い順)。
     *
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function comments(): Collection
    {
        return $this->event->comments()->with('user')->oldest()->oldest('id')->get();
    }

    /**
     * 「興味あり」を付け外しする。主催者自身のイベントでも押下可能(要件定義書4.4節)。
     */
    public function toggleLike(): void
    {
        $deleted = $this->event->likes()->where('user_id', Auth::id())->delete();

        if ($deleted === 0) {
            // 連打などで同時に登録リクエストが来てもユニーク制約違反で500にならないよう、createOrFirstで登録する
            $this->event->likes()->createOrFirst(['user_id' => Auth::id()]);
        }

        unset($this->likesCount, $this->isLiked);
    }

    /**
     * コメントを投稿する。
     */
    public function postComment(): void
    {
        $this->validate();

        $comment = new Comment(['body' => trim($this->body)]);
        $comment->user()->associate(Auth::user());
        $this->event->comments()->save($comment);

        $this->reset('body');
        unset($this->comments);
    }

    /**
     * コメントを削除する(投稿者本人のみ)。
     */
    public function deleteComment(int $commentId): void
    {
        // 他のイベントのコメントIDを指定されても削除できないよう、このイベントのコメントに限定して探す
        $comment = $this->event->comments()->findOrFail($commentId);

        $this->authorize('delete', $comment);

        $comment->delete();

        unset($this->comments);
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
                    <dd class="text-gray-900">
                        <a href="{{ route('users.show', $event->organizer) }}" wire:navigate class="hover:underline">{{ $event->organizer->display_name }}</a>
                    </dd>

                    <dt class="font-medium text-gray-500">参加人数</dt>
                    <dd class="text-gray-900">
                        <span class="font-semibold" data-testid="participants-count">{{ $this->participantsCount }}</span>
                        / 定員{{ $event->capacity }}名
                    </dd>
                </dl>

                <div>
                    <h2 class="text-sm font-medium text-gray-500">説明</h2>
                    <p class="mt-2 whitespace-pre-line text-gray-900">{{ $event->description }}</p>
                </div>

                {{-- 参加申込み・取消し。状態(主催者本人/終了/申込み済み/定員到達/申込み可)に応じて表示を切り替える --}}
                <div class="space-y-2 border-t border-gray-100 pt-6" data-testid="participation">
                    @php($isFull = $this->participantsCount >= $event->capacity)
                    @if ($event->organizer_id === auth()->id())
                        <p class="text-sm text-gray-600">あなたが主催するイベントです(主催者は参加申込みの対象外です)。</p>
                    @elseif ($event->isEnded())
                        <p class="text-sm text-gray-600">
                            {{ $this->isJoined ? 'このイベントに参加申込みしていました(開催済み)。' : 'このイベントは終了したため、申込みを受け付けていません。' }}
                        </p>
                    @elseif ($this->isJoined)
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm font-medium text-green-700">参加申込み済みです</span>
                            <x-secondary-button
                                wire:click="leave"
                                wire:confirm="参加申込みを取り消します。よろしいですか?"
                                wire:loading.attr="disabled"
                                wire:target="leave"
                            >
                                参加を取り消す
                            </x-secondary-button>
                        </div>
                    @elseif ($isFull)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button type="button" disabled class="disabled:cursor-not-allowed disabled:opacity-50">参加申込みする</x-primary-button>
                            <span class="text-sm font-medium text-red-600">定員に達しました</span>
                        </div>
                    @else
                        <x-primary-button type="button" wire:click="join" wire:loading.attr="disabled" wire:target="join" class="disabled:opacity-50">
                            参加申込みする
                        </x-primary-button>
                    @endif
                    <x-input-error :messages="$errors->get('participation')" />
                </div>

                <div class="flex items-center gap-3 border-t border-gray-100 pt-6">
                    <button
                        type="button"
                        wire:click="toggleLike"
                        wire:loading.attr="disabled"
                        wire:target="toggleLike"
                        aria-pressed="{{ $this->isLiked ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-pink-500 focus:ring-offset-2 disabled:opacity-50',
                            'border-pink-600 bg-pink-600 text-white hover:bg-pink-700' => $this->isLiked,
                            'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $this->isLiked,
                        ])
                    >
                        <span aria-hidden="true">{{ $this->isLiked ? '★' : '☆' }}</span>
                        興味あり
                    </button>
                    <p class="text-sm text-gray-600">
                        <span class="font-semibold text-gray-900" data-testid="likes-count">{{ $this->likesCount }}</span>人が興味あり
                    </p>
                </div>
            </div>
        </article>

        @can('viewParticipants', $event)
            <section class="mx-auto mt-6 max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg" aria-labelledby="participants-heading">
                <h2 id="participants-heading" class="text-lg font-medium text-gray-900">
                    参加者一覧<span class="ml-1 text-sm font-normal text-gray-500">({{ $this->participations->count() }}名・主催者のみ閲覧できます)</span>
                </h2>

                @if ($this->participations->isEmpty())
                    <p class="mt-4 text-sm text-gray-500">まだ参加申込みはありません。</p>
                @else
                    <ul class="mt-4 divide-y divide-gray-100">
                        @foreach ($this->participations as $participation)
                            <li wire:key="participation-{{ $participation->id }}" class="flex items-center justify-between gap-4 py-3 text-sm">
                                <a href="{{ route('users.show', $participation->user) }}" wire:navigate class="font-medium text-gray-900 hover:underline">
                                    {{ $participation->user->display_name }}
                                </a>
                                <time datetime="{{ $participation->created_at->toIso8601String() }}" class="text-gray-500">
                                    申込み: {{ $participation->created_at->format('Y/m/d H:i') }}
                                </time>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endcan

        <section class="mx-auto mt-6 max-w-3xl bg-white p-6 shadow-sm sm:rounded-lg" aria-labelledby="comments-heading">
            <h2 id="comments-heading" class="text-lg font-medium text-gray-900">
                コメント<span class="ml-1 text-sm font-normal text-gray-500">({{ $this->comments->count() }}件)</span>
            </h2>

            @if ($this->comments->isEmpty())
                <p class="mt-4 text-sm text-gray-500">まだコメントはありません。</p>
            @else
                <ul class="mt-4 divide-y divide-gray-100">
                    @foreach ($this->comments as $comment)
                        <li wire:key="comment-{{ $comment->id }}" class="py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="text-sm">
                                    <span class="font-medium text-gray-900">{{ $comment->user->display_name }}</span>
                                    <time datetime="{{ $comment->created_at->toIso8601String() }}" class="ml-2 text-gray-500">
                                        {{ $comment->created_at->format('Y/m/d H:i') }}
                                    </time>
                                </div>
                                @can('delete', $comment)
                                    <button
                                        type="button"
                                        wire:click="deleteComment({{ $comment->id }})"
                                        wire:confirm="このコメントを削除します。よろしいですか?"
                                        class="text-sm text-red-600 hover:text-red-800 hover:underline"
                                    >
                                        削除
                                    </button>
                                @endcan
                            </div>
                            <p class="mt-1 whitespace-pre-line break-words text-gray-900">{{ $comment->body }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="postComment" class="mt-6 space-y-3 border-t border-gray-100 pt-6">
                <x-input-label for="comment-body" value="コメントを投稿" />
                <textarea
                    id="comment-body"
                    wire:model="body"
                    rows="3"
                    maxlength="1000"
                    placeholder="質問やコメントを書いてください"
                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                ></textarea>
                <x-input-error :messages="$errors->get('body')" />
                <x-primary-button wire:loading.attr="disabled" wire:target="postComment">投稿する</x-primary-button>
            </form>
        </section>
    </div>
</div>
