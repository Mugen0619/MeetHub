<?php

namespace App\Livewire\Forms;

use App\Models\Event;
use App\Models\User;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * イベント作成・編集で共通の入力フォーム。
 */
class EventForm extends Form
{
    public ?Event $event = null;

    public string $title = '';

    /** datetime-local入力の値(例: 2026-10-01T19:00)。アプリのタイムゾーン(Asia/Tokyo)として解釈する */
    public string $starts_at = '';

    public string $location = '';

    public string $description = '';

    /** 数値入力のため、未入力時は空文字列が入る */
    public int|string|null $capacity = null;

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    /** 編集時に、既存の画像を削除するか */
    public bool $remove_image = false;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'location' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // 定員は1以上の整数を必須とする(無制限[null]は許容しない。要件定義書4.2節)
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'required' => ':attributeを入力してください。',
            'string' => ':attributeは文字列で入力してください。',
            'max.string' => ':attributeは:max文字以内で入力してください。',
            'date' => ':attributeは正しい日時で入力してください。',
            'starts_at.after' => ':attributeは現在より後の日時を指定してください。',
            'integer' => ':attributeは整数で入力してください。',
            'min.numeric' => ':attributeは:min以上で入力してください。',
            'max.numeric' => ':attributeは:max以下で入力してください。',
            'image' => ':attributeには画像ファイルを指定してください。',
            'mimes' => ':attributeはJPEG・PNG・WebP形式のファイルを指定してください。',
            'max.file' => ':attributeは:maxKB以下のファイルを指定してください。',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'タイトル',
            'starts_at' => '開催日時',
            'location' => '開催場所',
            'description' => '説明',
            'capacity' => '定員',
            'image' => '画像',
        ];
    }

    /**
     * 編集対象のイベントでフォームを初期化する。
     */
    public function setEvent(Event $event): void
    {
        $this->event = $event;
        $this->title = $event->title;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->location = $event->location;
        $this->description = $event->description;
        $this->capacity = $event->capacity;
    }

    /**
     * 新しいイベントを作成する。
     */
    public function store(User $organizer): Event
    {
        $validated = $this->validate();

        $event = new Event($this->attributes($validated));
        $event->organizer()->associate($organizer);
        $event->image_url = $this->image ? $this->storeImage() : null;
        $event->save();

        return $event;
    }

    /**
     * 編集対象のイベントを更新する。
     */
    public function update(): Event
    {
        $validated = $this->validate();
        /** @var Event $event */
        $event = $this->event;

        $event->fill($this->attributes($validated));

        $replacesImage = $this->image || $this->remove_image;
        $previous = clone $event;

        if ($replacesImage) {
            $event->image_url = $this->image ? $this->storeImage() : null;
        }

        $event->save();

        if ($replacesImage) {
            // 保存に成功してから、差し替え・削除前の古い画像ファイルを削除する
            $previous->deleteImageFile();
        }

        $this->reset('image', 'remove_image');

        return $event;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'title' => $validated['title'],
            'starts_at' => $validated['starts_at'],
            'location' => $validated['location'],
            'description' => $validated['description'],
            'capacity' => (int) $validated['capacity'],
        ];
    }

    /**
     * アップロードされた画像を、現在のディスク(FILESYSTEM_DISK)の events/ 配下に保存し、保存パスを返す。
     * S3の場合、一時ファイル(livewire-tmp/)はブラウザから署名付きURLで直接アップロード済みのため、
     * ここではS3内でのコピーのみが行われ、アプリサーバーを画像データが経由しない。
     */
    private function storeImage(): string
    {
        $path = $this->image->store('events', config('filesystems.default'));

        if ($path === false) {
            throw new \RuntimeException('イベント画像の保存に失敗しました。');
        }

        return $path;
    }
}
