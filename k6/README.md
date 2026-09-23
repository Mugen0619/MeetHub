# k6負荷試験

要件定義書[8. 非機能要件](../docs/requirements.md#8-非機能要件)で決定した、代表的な操作(ログイン・イベント一覧取得・参加申込み)の負荷試験。個人開発規模(数十仮想ユーザー程度)を想定し、本番相当の大規模負荷(数千〜数万VU)は対象外とする。

**ローカル実行のみを対象とし、CIでは実行しない**。CIの共有ランナーは性能が変動するため、レスポンスタイムの絶対値によるしきい値判定が信頼できないため。

構成(`lib/config.js`・`lib/auth.js`・`lib/report.js`、`scenarios/*.js`)、しきい値、段階的VU数、HTMLレポートは前回課題RAISETIMELINEの`k6/`を参考にした。ただしMeetHubはREST API + JWTではなく、セッション認証 + Livewireのため、スクリプトの中身は一から実装している([MeetHub特有の実装](#meethub特有の実装)参照)。

## 対象シナリオ

| シナリオ | ファイル | 対象の操作 | 負荷のかけ方 | 目的 |
|---|---|---|---|---|
| ログイン | `scenarios/login.js` | `GET /login` → `POST /livewire/update`(ログイン) | 段階的(10→30→50VU、5分) | レスポンスタイムの計測 |
| イベント一覧取得 | `scenarios/events.js` | `GET /events`(ログイン済み) | 段階的(10→30→50VU、5分) | レスポンスタイムの計測 |
| 参加申込み | `scenarios/join-event.js` | `POST /livewire/update`(参加申込み) | バースト(定員20のイベントに50VUが一斉に申込む) | **負荷がかかった状態でも定員を超過して登録されないことの検証** |

### しきい値

| シナリオ | しきい値 |
|---|---|
| login / events | 95%のリクエストが500ms以内(`http_req_duration: p(95)<500`)、失敗率1%未満(`http_req_failed: rate<0.01`)。`lib/config.js`の`DEFAULT_THRESHOLDS` |
| join-event | 申込み成功件数 == 定員(`join_succeeded: count==20`)、定員到達による拒否件数 == 参加者数 − 定員(`join_rejected_full: count==30`)、想定外の結果 == 0(`join_unexpected`)、全VUが開始時刻までに準備を終えたこと(`join_late_start: count==0`)、終了後にイベント詳細画面に表示される参加人数 == 定員(`event_participants_final: value==20`) |

join-eventはレスポンスタイムの計測が目的ではないため、レスポンスタイムのしきい値は設けていない。

## 前提条件

- [k6](https://k6.io/) がインストールされていること(Windowsの場合 `winget install GrafanaLabs.k6` 等。動作確認はk6 v2.2.0)
- アプリ・DBをDocker Composeで起動していること(リポジトリルートの[README.md](../README.md)参照)
- HTMLレポートの生成時に、k6-reporter・k6-summaryをインターネット(GitHub・jslib.k6.io)から読み込む

## 実行方法

### 1. サーバーを負荷試験用の設定で起動し直す

通常の開発用設定(`php artisan serve`)のままでは、以下の理由で正しく計測・検証できないため、負荷試験用の上書き設定([docker-compose.loadtest.yml](../docker-compose.loadtest.yml))で起動し直す。

- **1プロセスで1件ずつ処理される**: PHPのビルトインサーバーは既定ではリクエストを直列に処理するため、50人が同時に申込んでもサーバー側で順番に処理され、悲観ロックの競合が起きない(同時実行制御の検証にならない)。負荷試験用の設定では、`PHP_CLI_SERVER_WORKERS=8`で8ワーカーを起動する
- **Windowsのバインドマウントが遅い**: 開発用設定ではリポジトリ(Windows上のファイル)をコンテナにマウントしており、PHPのファイル読み込みが遅く並列にも処理されないため、アプリの性能と無関係に数十VUで頭打ちになる([参考: 開発用設定のまま実行した場合](#参考-開発用設定のまま実行した場合))。負荷試験用の設定では、起動時にコード一式をコンテナ内部にコピーしてから起動する(本番のECS Fargateも、コードをコンテナイメージ内にコピーして動かす)

```bash
docker compose -f docker-compose.yml -f docker-compose.loadtest.yml up -d app
# コードのコピーに30秒〜1分程度かかる。/health が200を返せば起動完了
curl http://localhost:8000/health
```

この設定では起動時点のコードのコピーで動くため、以降のコード・`.env`の変更は反映されない。また、アプリのログはコンテナ内部の`/tmp/app/storage/logs/laravel.log`に出力される。

```bash
docker compose exec app tail -f /tmp/app/storage/logs/laravel.log
```

### 2. シナリオを実行する

```bash
cd k6
k6 run scenarios/login.js
k6 run scenarios/events.js
k6 run scenarios/join-event.js
```

各シナリオは`setup()`内で、必要なテストデータ(専用ユーザー・イベント)を画面操作と同じリクエストで自動的に作成する。実行のたびに一意なユーザー名(`k6_<実行時刻>_...`)を使うため、繰り返し実行しても衝突しない。

- 対象URLはデフォルトで`http://localhost:8000`。変更する場合は`-e BASE_URL=...`で上書きする
- 動作確認だけしたい場合は、`--vus`/`--duration`で段階的VU数の設定を一時的に上書きできる(例: `k6 run --vus 1 --duration 5s scenarios/login.js`)
- join-eventの定員・参加者数・一斉申込みまでの待ち時間は`-e CAPACITY=3 -e PARTICIPANTS=6 -e START_DELAY_MS=5000`のように変更できる(スモークテスト用)
- join-eventで想定外の結果が出た場合、`-e DEBUG=1`でレスポンスの内容をコンソールに出力する

### 3. 参加者数をDBでも確認する(join-event)

join-eventはシナリオ内(teardown)でもイベント詳細画面の参加人数を確認するが、DBでも直接確認できる。イベントIDはk6の出力(`event 116: participants=20 / capacity=20`)に表示される。

```bash
docker compose exec pgsql psql -U meethub -d meethub -c "
  SELECT e.id, e.capacity, COUNT(p.id) AS participants
  FROM events e LEFT JOIN event_participations p ON p.event_id = e.id
  WHERE e.id = 116 GROUP BY e.id;"
```

### 4. サーバーを通常の設定に戻す

```bash
docker compose up -d app
```

## レポート

実行が終わると、`k6/report/`にHTML形式のレポート(`report-login.html`・`report-events.html`・`report-join-event.html`)が出力される。ブラウザで開くと、しきい値の合否・レスポンスタイムの分布・カスタムメトリクス(join-eventの成功/拒否件数等)を確認できる。実行のたびに生成される成果物のため、Git管理対象外(`.gitignore`)にしている。

## MeetHub特有の実装

RAISETIMELINE(REST API + JWT)と異なり、MeetHubの操作は「画面(HTML)をGET → 同じCookieのままLivewireの共通エンドポイントへPOST」の2段階になる。

### CSRFトークン + セッション認証(`lib/auth.js`)

ログインはLaravelのセッション認証で、CSRFトークンが必要になる。ログイン画面をGETして`<meta name="csrf-token">`のトークンを取得し、同じCookie(セッション)のままログイン処理をPOSTする。k6は同じVU内のリクエストでCookieを自動的に保持し、イテレーションごとにリセットする(login.jsは毎回未ログインの状態からログインする)。

events.js・join-event.jsでは、`setup()`で登録したユーザーのログイン済みセッションCookieを各VUに引き渡して使う(`useSession()`)。各VUが毎回ログインすると、計測したい操作よりもログイン処理(パスワードのハッシュ検証)の負荷が支配的になるため。

### Livewireのリクエスト(`lib/livewire.js`)

ボタン操作(`wire:click`・`wire:submit`)は、すべて共通の`POST /livewire/update`として送られる。形式はLivewire本体のクライアント(`vendor/livewire/livewire/dist/livewire.esm.js`の`sendRequest`・`toRequestPayload`)から確認し、同じ形式を再現している。

```
POST /livewire/update
Content-Type: application/json
X-Livewire:
{
  "_token": "<CSRFトークン>",
  "components": [{
    "snapshot": "<画面のHTMLの wire:snapshot 属性の値(JSON文字列のまま。チェックサムを含む)>",
    "updates": { "form.email": "...", "form.password": "..." },
    "calls": [{ "path": "", "method": "login", "params": [] }]
  }]
}
```

レスポンスの`components[0].effects`に、リダイレクト先(`redirect`)や再描画後のHTML(`html`)が入る。

- ログイン・登録・イベント作成の成否は`effects.redirect`の有無で判定する
- 参加申込みの成否は`effects.html`で判定する(申込み済みなら「参加申込み済みです」、定員到達で拒否された場合は例外メッセージ「定員に達しました。」が表示される)。コンポーネント側で`addError('participation', ...)`したエラーは、プロパティに紐づかないためLivewireの仕様上スナップショット(`memo.errors`)には含まれない

### 一斉申込みの仕組み(`scenarios/join-event.js`)

k6にはVU間で待ち合わせる機能がないため、`setup()`で「一斉に申込む時刻」(setup終了の15秒後)を決めて各VUに渡す。各VUはイベント詳細画面を取得して申込みボタンの状態(スナップショット)を準備したあと、その時刻まで待ってから申込む。準備が間に合わなかったVUがあれば`join_late_start`で検出し、試験自体を不合格にする。

## 実測結果

2026-09-24にローカル環境で実行した結果。実行環境やマシンスペックによって変わるため、あくまで参考値とする。

- 実行環境: Windows 11 + Docker Desktop(コンテナのCPU 16コア)、PHP 8.5.10(`php artisan serve`、8ワーカー、コードはコンテナ内部にコピー)、PostgreSQL 17.11、k6 v2.2.0
- アプリ設定: `.env.example`の値のまま(`APP_DEBUG=true`、`BCRYPT_ROUNDS=12`、セッション・キャッシュはDB、構造化ログ出力あり)。設定・ルートのキャッシュ(`php artisan optimize`)はしていない

### login / events

| シナリオ | 総リクエスト数 | 失敗率 | 平均 | p95 | しきい値(p95<500ms) |
|---|---|---|---|---|---|
| login(全体) | 10,342 | 0% | 284ms | 717ms | ❌ 未達 |
| └ `GET /login` | 5,170 | 0% | 159ms | 526ms | ❌ 未達 |
| └ `POST /livewire/update`(ログイン) | 5,170 | 0% | 410ms | 772ms | ❌ 未達 |
| events(`GET /events`) | 7,784 | 0% | 46ms | 75ms | ✅ 達成 |

VU数の段階ごとの内訳(各段階でVU数を維持している1分間のリクエストを、`--out csv`の出力から集計)。

| シナリオ | 10VU | 30VU | 50VU |
|---|---|---|---|
| login | p95 249ms(15.8 req/s) | p95 325ms(44.7 req/s) | p95 802ms(50.8 req/s) |
| events | p95 51ms(9.6 req/s) | p95 55ms(28.8 req/s) | p95 88ms(47.7 req/s) |

- **events**: 50VUまでp95が100ms未満で安定しており、しきい値を大きく下回った
- **login**: 30VUまではしきい値内だが、50VUでp95が約800msになり、全体でしきい値を超えた。ログイン処理(POST)の最小値が約200msで、これはパスワードのハッシュ検証(bcrypt、コスト12)の計算時間。1回あたり約200msのCPU処理を8ワーカーでしか並行処理できないため、50VUでは待ち行列が発生する。ログイン画面の表示(`GET /login`)も、同じワーカーをログイン処理と取り合う形で遅くなっている。bcryptのコストはセキュリティのため意図的に重くしているものなので下げない。本番(ECS Fargate)では、タスクのCPU数・PHP-FPMのワーカー数・タスク数で処理能力を確保する(AWS本番デプロイのIssueで、この結果をサイジングの参考にする)

### join-event(定員20のイベントに50VUが一斉に申込み)

| 項目 | 結果 |
|---|---|
| 申込み成功(`join_succeeded`) | 20件(= 定員) ✅ |
| 定員到達で拒否(`join_rejected_full`) | 30件 ✅ |
| 想定外の結果(`join_unexpected`) | 0件 ✅ |
| 開始時刻に遅れたVU(`join_late_start`) | 0件 ✅ |
| 終了後の参加人数(イベント詳細画面、`event_participants_final`) | 20人 ✅ |
| 終了後の参加人数(DBで直接確認) | 20人、同一ユーザーの重複登録なし ✅ |
| 申込みリクエストのレスポンスタイム | 平均197ms、p95 314ms、最大324ms |
| 50件の申込みが処理された時間の幅(アプリのログの時刻) | 約0.27秒(03:07:27.241〜27.513) |

50件の申込みが約0.27秒の間に8ワーカーで並行処理され、定員をちょうど満たす20件だけが登録された。悲観ロック(`Event::join()`の`lockForUpdate()`)による同時実行制御が、負荷がかかった状態でも正しく機能していることを確認できた。

**対照実験(このシナリオが定員超過を検出できることの確認)**: `Event::join()`から一時的に`lockForUpdate()`を外して同じシナリオを実行したところ、**参加人数26人 / 定員20人**と定員超過が発生し、`join_succeeded`・`event_participants_final`等のしきい値で不合格になった(確認後、コードは元に戻した)。ロックがなければ実際に競合が起き、このシナリオがそれを検出できることを確認している。

### 参考: 開発用設定のまま実行した場合

Windowsのファイルをバインドマウントしたままの状態(8ワーカー)でeventsを実行すると、アプリ本来の性能と無関係にスループットが頭打ちになった。コンテナのCPU使用率は最大約145%(16コア中1.5コア分)にとどまっており、CPUではなくファイル読み込みの遅さ・並列処理されないことが原因だった。

| 条件(events、50VU固定・60秒) | スループット | 平均 | p95 |
|---|---|---|---|
| バインドマウント、8ワーカー | 20.6 req/s | 1,390ms | 1,586ms |
| バインドマウント、16ワーカー | 27.4 req/s | 823ms | 1,371ms |
| コンテナ内部にコピー、16ワーカー | 47.5 req/s | 47ms | 78ms |

また、バインドマウント時はOPcacheが2秒ごとにPHPファイルの更新日時を確認し直す処理が非常に遅く、約2秒おきにリクエストが2〜5秒かかる現象も発生していた(コンテナ内部にコピーする方式では発生しない)。

## テストデータの後片付け

各シナリオの`setup()`で作成したユーザー(`username`が`k6_`で始まる)とそのイベント・参加申込みは、DBに残り続ける。関連テーブルの外部キーはすべて`ON DELETE CASCADE`のため、ユーザーを削除すればイベント・参加申込みも削除される。セッション(`sessions`テーブル)は外部キーではないため、先に削除する。

```bash
docker compose exec pgsql psql -U meethub -d meethub -c "
  DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'k6\_%');
  DELETE FROM users WHERE username LIKE 'k6\_%';"
```

login.jsは毎回未ログインの状態からログイン画面を開くため、未ログインのセッションも作られる。これらは`SESSION_LIFETIME`(120分)経過後、Laravelのセッションのガベージコレクションで自動的に削除される。

## ディレクトリ構成

```
k6/
├─ lib/
│  ├─ config.js     # BASE_URL・段階的VU数・しきい値の共通設定
│  ├─ auth.js       # ユーザー登録・ログイン(CSRFトークン取得を含む)・セッションCookieの引き渡し
│  ├─ livewire.js   # 画面のHTMLからLivewireコンポーネントを取り出し、/livewire/update を呼び出す
│  ├─ events.js     # イベント作成・イベントIDの取得・参加人数の取得(setup/teardown用)
│  └─ report.js     # HTMLレポート出力の共通ヘルパー(k6-reporterを使用)
├─ scenarios/
│  ├─ login.js
│  ├─ events.js
│  └─ join-event.js
├─ report/          # HTMLレポートの出力先(Git管理対象外)
└─ README.md
```
