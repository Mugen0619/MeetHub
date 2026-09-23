<?php

namespace App\Models;

use Database\Factories\EventLikeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * イベントへの「興味あり」(いいね相当)。
 */
#[Fillable(['user_id'])]
class EventLike extends Model
{
    /** @use HasFactory<EventLikeFactory> */
    use HasFactory;

    /**
     * 付け外しのみで更新されないため、updated_atは持たない。
     */
    public const UPDATED_AT = null;

    /**
     * 「興味あり」されたイベント。
     *
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * 「興味あり」したユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
