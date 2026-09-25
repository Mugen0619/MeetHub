<?php

use App\Models\Event;
use App\Models\User;

/*
| 代表的なユーザージャーニーのE2Eテスト(要件定義書8節)。
|
| 個々の機能の正しさ(バリデーション・認可・定員管理等)は tests/Feature の機能テストで担保しているため、
| テストピラミッドの考え方に従い、ここでは複数の機能をまたぐ一連の流れが実際のブラウザで動くことだけを確認する。
|
| Pestのブラウザテストの操作・アサーションは、期待した状態になるまで自動で再試行される(AwaitableWebpage)ため、
| Livewireの非同期な画面更新を明示的に待つ必要はない。
*/

function futureStartsAt(): string
{
    // datetime-local入力の形式(アプリのタイムゾーン Asia/Tokyo)
    return now()->addMonth()->setTime(19, 0)->format('Y-m-d\TH:i');
}

test('主催者: 新規登録 → イベント作成 → 編集 → 削除', function () {
    $page = visit('/register')
        ->fill('username', 'e2e_organizer')
        ->fill('display_name', 'E2E主催者')
        ->fill('email', 'organizer@example.com')
        ->fill('password', 'password123')
        ->fill('password_confirmation', 'password123')
        ->press('登録する')
        ->assertPathIs('/dashboard')
        ->assertSee('まだ主催しているイベントはありません。');

    // イベントを作成する
    $page->click('イベント作成')
        ->assertPathIs('/events/create')
        ->fill('title', 'E2E勉強会')
        ->fill('starts_at', futureStartsAt())
        ->fill('location', 'オンライン(Zoom)')
        ->fill('description', 'E2Eテストで作成したイベントです。')
        ->fill('capacity', '10')
        ->press('作成する')
        ->assertPathIs('/dashboard')
        ->assertSee('イベントを作成しました。')
        ->assertSee('E2E勉強会');

    // ダッシュボードの主催イベント一覧から編集する
    $page->click('編集')
        ->assertPathEndsWith('/edit')
        ->assertValue('#title', 'E2E勉強会')
        ->fill('title', 'E2E勉強会(改訂版)')
        ->press('更新する')
        ->assertPathIs('/dashboard')
        ->assertSee('イベントを更新しました。')
        ->assertSee('E2E勉強会(改訂版)');

    // 編集画面から削除する(確認ダイアログでOKを押す)
    $page->click('編集')->assertPathEndsWith('/edit');
    acceptConfirmDialogs($page);
    $page->press('削除する')
        ->assertPathIs('/dashboard')
        ->assertSee('イベントを削除しました。')
        ->assertSee('まだ主催しているイベントはありません。')
        ->assertNoJavaScriptErrors();

    // 他のテスト(コミットしてデータを残す同時実行テスト等)の影響を受けないよう、作成したイベントに絞って確認する
    expect(Event::where('title', 'like', 'E2E勉強会%')->exists())->toBeFalse();
});

test('参加者: 新規登録 → イベント一覧から詳細 → いいね → コメント → 参加申込み → 参加予定一覧で確認 → 取消し', function () {
    $organizer = User::factory()->create(['display_name' => '主催者さん']);
    $event = Event::factory()->for($organizer, 'organizer')->create([
        'title' => 'Laravel もくもく会',
        'capacity' => 5,
        'starts_at' => now()->addWeek(),
    ]);

    $page = visit('/register')
        ->fill('username', 'e2e_participant')
        ->fill('display_name', 'E2E参加者')
        ->fill('email', 'participant@example.com')
        ->fill('password', 'password123')
        ->fill('password_confirmation', 'password123')
        ->press('登録する')
        ->assertPathIs('/dashboard');

    // イベント一覧から詳細画面を開く
    $page->click('イベント')
        ->assertPathIs('/events')
        ->click('Laravel もくもく会')
        ->assertPathIs("/events/{$event->id}")
        ->assertSee('主催者さん');

    // いいね(興味あり)。ボタンの表示は「☆ 興味あり」だが、☆はaria-hiddenのため、
    // スクリーンリーダーと同じアクセシブルネーム(「興味あり」)でボタンを特定して押す
    $page->press('internal:role=button[name="興味あり"s]')
        ->assertAttribute('internal:role=button[name="興味あり"s]', 'aria-pressed', 'true')
        ->assertSeeIn('@likes-count', '1');

    // コメントを投稿する
    $page->fill('comment-body', '参加を検討しています!')
        ->press('投稿する')
        ->assertSee('参加を検討しています!')
        ->assertSee('E2E参加者');

    // 参加申込みする
    $page->press('参加申込みする')
        ->assertSee('参加申込み済みです')
        ->assertSeeIn('@participants-count', '1');

    // マイ参加予定一覧で確認し、取り消す(確認ダイアログでOKを押す)
    $page->click('参加予定')
        ->assertPathIs('/events/participating')
        ->assertSee('Laravel もくもく会');
    acceptConfirmDialogs($page);
    $page->press('取消し')
        ->assertSee('参加申込みした開催予定のイベントはありません。')
        ->assertNoJavaScriptErrors();

    expect($event->participations()->count())->toBe(0);
});

test('フォロー: ログイン → 主催者をフォロー → 「フォロー中」タブで絞り込み → フォロー解除', function () {
    $user = User::factory()->create(['email' => 'follower@example.com']);
    $followed = User::factory()->create(['display_name' => 'フォローする主催者']);
    $other = User::factory()->create(['display_name' => '他の主催者']);
    Event::factory()->for($followed, 'organizer')->create(['title' => 'フォロー先のイベント', 'starts_at' => now()->addDays(3)]);
    Event::factory()->for($other, 'organizer')->create(['title' => '他の人のイベント', 'starts_at' => now()->addDays(4)]);

    $page = visit('/login')
        ->fill('email', 'follower@example.com')
        ->fill('password', 'password')
        // 見出し(h1)も「ログイン」のため、テキストではなく送信ボタンを指定して押す
        ->press('button[type="submit"]')
        ->assertPathIs('/dashboard');

    // イベント一覧から主催者のプロフィールを開いてフォローする
    $page->click('イベント')
        ->assertSee('フォロー先のイベント')
        ->assertSee('他の人のイベント')
        ->click('フォローする主催者')
        ->assertPathIs("/users/{$followed->username}")
        ->press('フォローする')
        ->assertVisible('internal:role=button[name="フォロー解除"s]');

    // 「フォロー中」タブでは、フォローした主催者のイベントだけが表示される
    $page->click('イベント')
        ->press('フォロー中')
        ->assertSee('フォロー先のイベント')
        ->assertDontSee('他の人のイベント');

    // フォローを解除すると、「フォロー中」タブに表示されなくなる
    $page->click('フォロー先のイベント')
        ->click('フォローする主催者')
        ->press('フォロー解除')
        // 主催者名(「フォローする主催者」)に部分一致しないよう、ボタンのロールと名前で確認する
        ->assertVisible('internal:role=button[name="フォローする"s]');

    $page->click('イベント')
        ->press('フォロー中')
        ->assertSee('フォロー中の主催者の開催予定イベントはありません。')
        ->assertNoJavaScriptErrors();

    expect($user->followings()->count())->toBe(0);
});
