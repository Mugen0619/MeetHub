{{-- トップページ。ログイン・登録画面と同じゲスト向けレイアウト(ロゴ + 白いカード)で、MeetHubの紹介と各画面への導線を表示する --}}
<x-guest-layout>
    <x-slot name="heading">MeetHub</x-slot>

    <p class="text-sm leading-relaxed text-gray-700">
        勉強会・もくもく会などのイベントを告知し、参加者を集めるためのイベント掲示板です。
        主催者はイベントを投稿し、参加したい人は「興味あり」・コメント・参加申込みができます。
    </p>

    <ul class="mt-4 space-y-2 text-sm text-gray-700">
        <li class="flex gap-2">
            <span aria-hidden="true" class="text-indigo-600">✓</span>
            <span>開催日時・場所・定員・画像つきでイベントを作成</span>
        </li>
        <li class="flex gap-2">
            <span aria-hidden="true" class="text-indigo-600">✓</span>
            <span>参加申込み・取消し(定員に達したら締め切り)</span>
        </li>
        <li class="flex gap-2">
            <span aria-hidden="true" class="text-indigo-600">✓</span>
            <span>「興味あり」やコメントで、主催者に質問・反応</span>
        </li>
        <li class="flex gap-2">
            <span aria-hidden="true" class="text-indigo-600">✓</span>
            <span>主催者をフォローして、新着イベントをチェック</span>
        </li>
    </ul>

    <div class="mt-6 flex flex-col gap-3 sm:flex-row">
        @auth
            <a href="{{ route('events.index') }}" wire:navigate
                class="inline-flex flex-1 items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900">
                イベント一覧へ
            </a>
        @else
            <a href="{{ route('login') }}" wire:navigate
                class="inline-flex flex-1 items-center justify-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900">
                ログイン
            </a>
            <a href="{{ route('register') }}" wire:navigate
                class="inline-flex flex-1 items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                新規登録
            </a>
        @endauth
    </div>
</x-guest-layout>
