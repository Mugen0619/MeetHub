<?php

namespace App\Models;

use Database\Factories\EventParticipationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * イベントへの参加申込み(EventとUserの多対多の中間テーブル)。
 * 取消し時は行を削除する(取消し後も同じユーザーが再度申込みできるようにするため)。
 */
#[Fillable(['user_id'])]
class EventParticipation extends Model
{
    /** @use HasFactory<EventParticipationFactory> */
    use HasFactory;

    /**
     * 申込み・取消し(行削除)のみで更新されないため、updated_atは持たない。
     */
    public const UPDATED_AT = null;

    /**
     * 申込み先のイベント。
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * 申込みしたユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
