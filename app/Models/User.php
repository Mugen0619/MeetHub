<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
