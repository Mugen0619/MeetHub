<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable(['username', 'display_name', 'email', 'password', 'bio', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * 退会(アカウント削除)時に、保存済みのアイコン画像ファイルも合わせて削除する。
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user) {
            $user->deleteAvatarFile();
        });
    }

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

    /**
     * ユーザーが参加申込みしたイベント一覧。
     *
     * @return BelongsToMany<Event, $this>
     */
    public function participatingEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_participations')
            ->withPivot('created_at');
    }

    /**
     * アイコン画像の公開URL。
     *
     * イベント画像(Event::imageUrl)と同じく、avatar_urlカラムにはディスク上の保存パス(例: avatars/xxx.png)を保存し、
     * 表示時に現在のディスク(FILESYSTEM_DISK)の公開URLへ変換する。
     *
     * @return Attribute<string|null, string|null>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => match (true) {
            $value === null || $value === '' => null,
            Str::startsWith($value, ['http://', 'https://']) => $value,
            default => Storage::url($value),
        });
    }

    /**
     * 保存済みのアイコン画像ファイルを削除する(外部URLの場合は何もしない)。
     */
    public function deleteAvatarFile(): void
    {
        $path = $this->getRawOriginal('avatar_url');

        if (is_string($path) && $path !== '' && ! Str::startsWith($path, ['http://', 'https://'])) {
            Storage::delete($path);
        }
    }
}
