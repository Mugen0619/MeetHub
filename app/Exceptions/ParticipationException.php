<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 参加申込み・取消しが業務ルール上受け付けられない場合の例外。
 * メッセージはそのまま画面に表示し、reason(理由コード)はログに記録する。
 */
class ParticipationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function organizer(): self
    {
        return new self('主催者は自分のイベントに参加申込みできません。', 'organizer');
    }

    public static function ended(): self
    {
        return new self('開催日時を過ぎたイベントには申込みできません。', 'ended');
    }

    public static function alreadyJoined(): self
    {
        return new self('このイベントには既に申込み済みです。', 'already_joined');
    }

    public static function full(): self
    {
        return new self('定員に達しました。', 'full');
    }

    public static function cancelAfterStart(): self
    {
        return new self('開催日時を過ぎたイベントの申込みは取り消せません。', 'cancel_after_start');
    }
}
