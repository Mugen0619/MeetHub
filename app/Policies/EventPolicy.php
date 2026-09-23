<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    /**
     * イベントを編集できるか。
     * 主催者本人のみ。開催日時を過ぎた(終了した)イベントは、参加実績の記録として内容を固定するため編集不可とする。
     */
    public function update(User $user, Event $event): bool
    {
        return $user->id === $event->organizer_id && ! $event->isEnded();
    }

    /**
     * イベントを削除できるか。
     * 主催者本人のみ。終了したイベントも主催者が整理できるよう削除は許可する。
     */
    public function delete(User $user, Event $event): bool
    {
        return $user->id === $event->organizer_id;
    }
}
