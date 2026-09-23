<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;

#[Fillable(['username', 'display_name', 'email', 'password', 'bio', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * ユーザーが主催するイベント一覧。
     *
     * @return HasMany<Event, $this>
     */
    public function organizedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    /**
     * このユーザーがフォローしているユーザー一覧。
     *
     * @return BelongsToMany<User, $this>
     */
    public function followings(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followee_id')
            ->withPivot('created_at');
    }

    /**
     * このユーザーをフォローしているユーザー一覧。
     *
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followee_id', 'follower_id')
            ->withPivot('created_at');
    }

    /**
     * 指定したユーザーをフォローする(フォロー済みの場合は何もしない)。
     *
     * @throws InvalidArgumentException 自分自身をフォローしようとした場合
     */
    public function follow(User $user): void
    {
        if ($this->is($user)) {
            throw new InvalidArgumentException('自分自身はフォローできません。');
        }

        // 連打・複数タブからの同時リクエストでも、ユニーク制約違反にせず1件にまとめる
        Follow::createOrFirst([
            'follower_id' => $this->id,
            'followee_id' => $user->id,
        ]);
    }

    /**
     * 指定したユーザーのフォローを解除する(フォローしていない場合は何もしない)。
     */
    public function unfollow(User $user): void
    {
        $this->followings()->detach($user->id);
    }

    /**
     * 指定したユーザーをフォローしているかどうか。
     */
    public function isFollowing(User $user): bool
    {
        return $this->followings()->whereKey($user->id)->exists();
    }

    /**
     * ユーザーが「興味あり」したイベントの記録一覧。
     *
     * @return HasMany<EventLike, $this>
     */
    public function eventLikes(): HasMany
    {
        return $this->hasMany(EventLike::class);
    }

    /**
     * ユーザーが投稿したコメント一覧。
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
