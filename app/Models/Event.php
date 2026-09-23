<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property Carbon $starts_at
 */
#[Fillable(['title', 'description', 'location', 'starts_at', 'capacity', 'image_url'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * 削除時に、保存済みの画像ファイルも合わせて削除する。
     * (主催者アカウント削除によるDBの連鎖削除ではモデルイベントが発火しないため、その場合の画像は残る)
     */
    protected static function booted(): void
    {
        static::deleted(function (Event $event) {
            $event->deleteImageFile();
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
            'starts_at' => 'datetime',
            'capacity' => 'integer',
        ];
    }

    /**
     * イベントの主催者。
     *
     * @return BelongsTo<User, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /**
     * イベントへの「興味あり」一覧。
     *
     * @return HasMany<EventLike, $this>
     */
    public function likes(): HasMany
    {
        return $this->hasMany(EventLike::class);
    }

    /**
     * イベントへのコメント一覧。
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * 画像の公開URL。
     *
     * image_urlカラムにはディスク上の保存パス(例: events/xxx.jpg)を保存し、
     * 表示時に現在のディスク(FILESYSTEM_DISK)の公開URLへ変換する。
     * ディスクをlocal/s3で切り替えても、DBの値を書き換えずに済むようにするため。
     *
     * @return Attribute<string|null, string|null>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (?string $value) => match (true) {
            $value === null || $value === '' => null,
            Str::startsWith($value, ['http://', 'https://']) => $value,
            default => Storage::url($value),
        });
    }

    /**
     * 開催日時を過ぎた(終了した)イベントかどうか。
     */
    public function isEnded(): bool
    {
        return $this->starts_at->isPast();
    }

    /**
     * 保存済みの画像ファイルを削除する(外部URLの場合は何もしない)。
     */
    public function deleteImageFile(): void
    {
        $path = $this->getRawOriginal('image_url');

        if (is_string($path) && $path !== '' && ! Str::startsWith($path, ['http://', 'https://'])) {
            Storage::delete($path);
        }
    }
}
