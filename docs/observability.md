# 構造化ログ・ヘルスチェック

[要件定義書](./requirements.md)へ戻る

要件定義書[8. 非機能要件](./requirements.md#8-非機能要件)の「構造化ログ(JSON化、traceId/userId付与)」の設計をまとめる。ログの読み方・障害調査の手順は[operations-guide.md](./operations-guide.md)を参照。

設計の考え方(何を記録するか・ログレベルの使い分け・秘密情報を出さない方針)は前回課題RAISETIMELINEの`docs/observability.md`を参考にしている。ただしRAISETIMELINEはJava/Spring Boot(logstash-logback-encoder)のため、実装はLaravel/Monologで一から行った。

## 対象外

- Datadog等の外部監視ツールとの連携、ヘルスチェック失敗時の外部通知(メール・Slack)
- 分散トレーシング基盤の構築(単一サービスのため、`traceId`はリクエスト単位のシンプルなUUID発行のみ)
- ログ集約基盤での検索・アラート設定(本番のログはCloudWatch Logsに集約している。[infrastructure.md](./infrastructure.md)参照)

## 技術スタック

| 技術 | バージョン | 用途 |
|---|---|---|
| Monolog | 3.12系(Laravel 13系に同梱) | `Monolog\Formatter\JsonFormatter`によるJSON出力 |
| spatie/laravel-health | 1.40系 | ヘルスチェックの詳細(`/health/details`)。ALB用の`/health`は使わない |

## 出力形式

`config/logging.php`の`json`チャンネル(`.env`の`LOG_CHANNEL=json`)で、`storage/logs/laravel.log`に1行1レコードのJSONとして出力する。例外発生時はスタックトレースもJSON内の文字列として含める(`includeStacktraces`)。

```json
{"message":"participation rejected","context":{"traceId":"af4606d6-c5df-45b8-b881-57ebb69164e0","userId":7,"eventId":4,"reason":"full"},"level":300,"level_name":"WARNING","channel":"local","datetime":"2026-09-24T00:16:14.536813+09:00","extra":{}}
```

| フィールド | 内容 |
|---|---|
| `message` | ログの種類を表す固定の英語文字列(例: `login failed`)。検索・集計のキーにするため、値を埋め込まない |
| `level_name` / `level` | ログレベル(`INFO`/`WARNING`/`ERROR`等)とその数値 |
| `datetime` | 出力日時(`config/app.php`の`timezone`、Asia/Tokyo) |
| `channel` | 実行環境名(`APP_ENV`。`local`/`production`等) |
| `context.traceId` | リクエスト単位で発行するUUID(後述) |
| `context.userId` | ログイン中ユーザーのID。未ログイン時はキー自体を含めない(後述) |
| `context.*` | ログごとの付加情報(次節の表を参照) |

## traceId / userIdの付与

| 項目 | 付与する仕組み |
|---|---|
| `traceId` | `App\Http\Middleware\RequestLogging`がリクエスト受付時に`Str::uuid()`で発行し、`Log::withContext()`に登録する。他のミドルウェアのログにも付くよう、グローバルミドルウェアの先頭で実行する(`bootstrap/app.php`)。同じ値をレスポンスヘッダー`X-Trace-Id`でも返す |
| `userId` | `App\Listeners\LogAuthenticationEvents`が、Laravelの`Authenticated`イベント(セッションからユーザーを復元した時・ログインした時に発火)で`Log::withContext()`に登録する。ログアウト時(`Logout`イベント)に取り除く |

- `RequestLogging`はリクエストの最初に`Log::withoutContext()`でコンテキストを空にしてから`traceId`を登録する。同じプロセスが複数のリクエストを処理する場合(テスト実行時や、将来Laravel Octane等を導入した場合)に、前のリクエストのuserIdが混ざらないようにするため
- ガードが既にユーザーを保持している場合(テストの`actingAs`等)は`Authenticated`イベントが再発火しないため、`RequestLogging`が`Auth::hasUser()`を確認して`userId`を登録する
- `Log::withContext()`は既定のログチャンネル(`LOG_CHANNEL`)にのみ適用される。`Log::channel('xxx')`で別チャンネルに出力する場合は付与されない
- HTTPリクエスト以外(Artisanコマンド・キューのジョブ)には`traceId`は付かない

## ログレベルの使い分け

| レベル | 使う場面 | 例 |
|---|---|---|
| `ERROR` | 予期しない例外(実装バグ・DB障害等)。対応が必要 | 500エラー(Laravelの例外ハンドラーが自動で記録) |
| `WARNING` | クライアント起因で処理を拒否した場合、または不正操作・攻撃の兆候。単発なら対応不要だが、急増したら調査する | ログイン失敗、ログイン試行回数の上限到達、参加申込みの拒否、権限エラー(403) |
| `INFO` | 正常な業務イベント・アクセスログ。後から「いつ誰が何をしたか」を追うために残す | ログイン成功、ユーザー登録、参加申込み・取消し、アクセスログ |
| `DEBUG` | 開発時の一時的な調査用。本番ではコミットしない | - |

本番環境では`LOG_LEVEL=info`とし、DEBUGを出力しない。本番のログは`json_stderr`チャンネル(同じJSON形式で標準エラー出力へ)で出力し、ECSのログドライバ(awslogs)でCloudWatch Logsに集約する([infrastructure.md](./infrastructure.md))。

## 記録するイベント

| message | レベル | 付加情報(context) | 出力箇所 |
|---|---|---|---|
| `http request completed` | INFO | `httpStatus`, `method`, `endpoint`, `durationMs` | `RequestLogging`(1リクエストにつき1行。正常な`/health`は除く) |
| `login succeeded` | INFO | `userId` | `LogAuthenticationEvents`(`Login`イベント) |
| `login failed` | WARNING | `targetUserId`(存在するアカウントへのパスワード誤りの場合のみ), `ip` | 同上(`Failed`イベント) |
| `login locked out` | WARNING | `ip` | 同上(`Lockout`イベント。5回失敗した後の次の試行でレート制限に到達) |
| `logout` | INFO | - | 同上(`Logout`イベント) |
| `user registered` | INFO | `userId` | 同上(`Registered`イベント) |
| `participation created` | INFO | `eventId` | `Event::join()` |
| `participation rejected` | WARNING | `eventId`, `reason` | `Event::join()` |
| `participation cancelled` | INFO | `eventId` | `Event::leave()`(実際に取り消した場合のみ) |
| `participation cancel rejected` | WARNING | `eventId`, `reason` | `Event::leave()` |
| `authorization denied` | WARNING | `exceptionType`, `exceptionMessage` | `bootstrap/app.php`の例外ハンドラー設定 |
| (例外メッセージ) | ERROR | `exception`(クラス名・メッセージ・ファイル・スタックトレース) | Laravel標準の例外ハンドラー |

参加申込みの`reason`は`App\Exceptions\ParticipationException`の理由コード。

| reason | 意味 |
|---|---|
| `full` | 定員に達している |
| `ended` | 開催日時を過ぎている |
| `already_joined` | 既に申込み済み(二重送信等) |
| `organizer` | 主催者本人による申込み(画面上はボタンが出ないため、通常は発生しない) |
| `cancel_after_start` | 開催日時を過ぎたイベントの取消し |

### 設計上の判断

- **権限エラー(403)**: Laravelは`AuthorizationException`を標準ではログに記録しない。他人のリソースを操作しようとした記録は不正操作の調査に必要なため、`stopIgnoring()`で記録対象に戻し、WARNINGで記録する。想定内のエラーのため、ERRORレベル・スタックトレース付きの標準の例外ログは抑止している
- **参加申込みの拒否**: 定員到達等は業務上想定内の結果だが、「申込みできなかった」という問い合わせの調査に使えるよう、他のクライアント起因のエラーと揃えてWARNINGとし、`reason`で理由を区別できるようにした。記録は画面(Livewireコンポーネント)ではなく`Event::join()`/`Event::leave()`で行い、呼び出し元が増えても記録漏れが起きないようにしている
- **アクセスログの`endpoint`**: 実際のパスではなくルート定義のURI(例: `/events/{event}`、`/reset-password/{token}`)を記録する。パスワードリセットURLのトークンがログに残らないようにするためと、同じ画面へのアクセスを集計しやすくするため
- **Livewireの操作**: 画面上のボタン操作(ログイン・参加申込み等)は、すべて`POST /livewire/update`へのリクエストになる。そのためアクセスログだけではどの操作かは分からず、上表の業務イベントのログを同じ`traceId`で突き合わせて確認する

## 秘密情報・個人情報の扱い

- パスワード・セッションID・CSRFトークン・パスワードリセットトークンはログに出力しない。アクセスログにはHTTPメタデータ(ステータス・メソッド・ルートのURI・処理時間)のみを含め、リクエストボディ・クエリ文字列・Cookieは含めない
- メールアドレスは個人情報のため、ログイン失敗時も記録しない。代わりに、存在するアカウントの場合はそのユーザーID(`targetUserId`)を記録し、特定アカウントへの総当たりを検知できるようにする
- ログイン失敗・ロックアウトにはIPアドレス(`ip`)を記録する。本番(CloudFront → ALB配下)では、信頼するプロキシ(ALBとCloudFront)を`config/trustedproxy.php`で設定し、閲覧者の実際のIPを記録する([infrastructure.md](./infrastructure.md))
- 予期しない例外(ERROR)のメッセージには、DBのエラー内容等が含まれうる。ログはアプリケーション外部に公開しない前提とする

## ヘルスチェック

以下の2つのエンドポイントを用意する(`routes/health.php`)。

| エンドポイント | 用途 | 確認する内容 | レスポンス |
|---|---|---|---|
| `GET /health` | ALB(ECSのタスクの生死判定)のヘルスチェック | Nginx → PHP-FPM → Laravelが起動してリクエストを処理できること(`LivenessController`)。**DB等の外部依存は確認しない** | 常に`200 {"healthy":true}` |
| `GET /health/details` | 障害調査・ローカル開発での確認用 | `spatie/laravel-health`による、データベース接続(`DatabaseCheck`)とキャッシュの読み書き(`CacheCheck`)。チェックは`AppServiceProvider`で登録する | チェックごとの結果(JSON)。`.env`の`HEALTH_EXPOSE_DETAILS=true`の場合のみ有効で、未設定・`false`の場合は`404`を返す(本番では設定しない) |

- **`/health`にDB接続を含めない理由**: 含めると、RDSの障害時に全タスクが`unhealthy`と判定され、ECSがタスクの入れ替えを繰り返す(タスクを入れ替えてもDB障害は直らず、再起動の負荷が増えるだけになる)。タスクを入れ替えるべきなのは「そのタスク自体が応答できない」場合だけのため、ヘルスチェックはプロセスの生存確認に限定し、DB障害はアプリのERRORログ(DB接続エラーの例外)で検知する。当初は`/health`でもDBを確認していたが、AWS本番デプロイ(Issue #24)で見直した
- ALBから定期的に呼ばれるため、セッション(`SESSION_DRIVER=database`によるDBへの書き込み)を伴う`web`ミドルウェアグループには含めない
- `/health/details`のチェック結果はリクエストのたびにその場で実行し、プロセス内のメモリ(`InMemoryHealthResultStore`)にだけ保持する。結果保存用のDBテーブルや、定期実行(スケジューラー)は使わない
- 正常なヘルスチェックはアクセスログに記録しない(ログが埋め尽くされるため)
- Laravel標準のヘルスチェック(`/up`)は、エンドポイントを整理するため廃止した
