<?php

namespace App\Models;

use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ユーザー間のフォロー関係(follower が followee をフォローしている)。
 */
#[Fillable(['follower_id', 'followee_id'])]
class Follow extends Model
{
    /** @use HasFactory<FollowFactory> */
    use HasFactory;

    /**
     * フォローは作成・削除のみで更新しないため、updated_atカラムは持たない。
     */
    public const UPDATED_AT = null;

    /**
     * フォローしている側のユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /**
     * フォローされている側のユーザー。
     *
     * @return BelongsTo<User, $this>
     */
    public function followee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followee_id');
    }
}
