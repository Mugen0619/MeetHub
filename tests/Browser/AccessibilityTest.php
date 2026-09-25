<?php

use App\Models\Comment;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;

/*
| 主要画面のアクセシビリティ検査(axe-core)。
|
| Pestのブラウザテストは、axe-core本体(pest-plugin-browserに同梱)を全ページに注入しており、
| assertNoAccessibilityIssues()でページ内の axe.run()(デフォルトルールセット)を実行して違反を取得する。
| 引数は検出対象とする違反の影響度(0: critical 〜 3: minor)で、既定の1ではcritical・seriousしか検出しないため、
| 3を指定してデフォルトルールセットの違反をすべて検出する。
|
| 違反が見つかった場合は、テスト側ではなく製品コード側(Blade・Livewireコンポーネント)を修正する。
*/

const ALL_ACCESSIBILITY_ISSUES = 3;

test('トップページ', function () {
    visit('/')
        ->assertSee('MeetHub')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('ログイン画面', function () {
    visit('/login')
        ->assertSee('ログイン状態を保持する')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('登録画面', function () {
    visit('/register')
        ->assertSee('登録する')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('イベント一覧画面', function () {
    // 一覧の各項目(タイトル・日時・主催者等)も検査できるよう、イベントを表示した状態で検査する
    Event::factory()->count(3)->create();
    $this->actingAs(User::factory()->create());

    visit('/events')
        ->assertSee('すべて')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('イベント詳細画面(参加者として閲覧)', function () {
    $event = Event::factory()->create(['capacity' => 10]);
    EventParticipation::factory()->for($event)->create();
    Comment::factory()->for($event)->create(['body' => 'アクセシビリティ検査用のコメント']);
    $this->actingAs(User::factory()->create());

    visit("/events/{$event->id}")
        ->assertSee('アクセシビリティ検査用のコメント')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('イベント詳細画面(主催者として閲覧、参加者一覧を含む)', function () {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();
    EventParticipation::factory()->for($event)->create();
    $this->actingAs($organizer);

    visit("/events/{$event->id}")
        ->assertSee('参加者一覧')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('プロフィール画面', function () {
    $user = User::factory()->create();
    Event::factory()->for($user, 'organizer')->count(2)->create();
    $this->actingAs(User::factory()->create());

    visit("/users/{$user->username}")
        ->assertSee($user->display_name)
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});

test('プロフィール編集画面', function () {
    $this->actingAs(User::factory()->create(['bio' => 'アクセシビリティ検査用の自己紹介']));

    visit('/profile')
        ->assertSee('自己紹介')
        ->assertNoAccessibilityIssues(ALL_ACCESSIBILITY_ISSUES);
});
