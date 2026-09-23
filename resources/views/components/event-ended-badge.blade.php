{{-- 開催日時を過ぎたイベントに「終了」バッジを表示する(開催予定のイベントでは何も表示しない) --}}
@props(['event'])

@if ($event->isEnded())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full bg-gray-200 px-2.5 py-0.5 text-xs font-semibold text-gray-700']) }}>
        終了
    </span>
@endif
