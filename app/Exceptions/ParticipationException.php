<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 参加申込み・取消しが業務ルール上受け付けられない場合の例外。
 * メッセージはそのまま画面に表示する。
 */
class ParticipationException extends RuntimeException
{
    public static function organizer(): self
    {
        return new self('主催者は自分のイベントに参加申込みできません。');
    }

    public static function ended(): self
    {
        return new self('開催日時を過ぎたイベントには申込みできません。');
    }

    public static function alreadyJoined(): self
    {
        return new self('このイベントには既に申込み済みです。');
    }

    public static function full(): self
    {
        return new self('定員に達しました。');
    }

    public static function cancelAfterStart(): self
    {
        return new self('開催日時を過ぎたイベントの申込みは取り消せません。');
    }
}
