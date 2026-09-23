<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * コメントを削除できるか。
     * 投稿者本人のみ(イベントの主催者であっても他人のコメントは削除できない。要件定義書4.4節)。
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $user->id === $comment->user_id;
    }
}
