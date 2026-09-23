# 運用ガイド(ログ調査・簡易インシデント対応)

[要件定義書](./requirements.md)へ戻る

ログの構造(JSON化・traceId/userId付与・記録するイベント)は[observability.md](./observability.md)を参照。本ドキュメントは、実際に障害調査を行う際に「どのログをどう検索するか」「何を確認すればよいか」を、個人開発規模を想定して簡潔にまとめたものである。

## 前提: ログの出力先

ローカル開発環境では、ログは`storage/logs/laravel.log`にJSON形式(1行1レコード)で出力される。以下のコマンド例は、いずれもこのファイルに対して実行する前提とする(Docker環境の場合、ホスト側のリポジトリ内の同じパスにも見える)。

```bash
# 新しく出力されたログをリアルタイムで追う
tail -f storage/logs/laravel.log
```

`jq`があると、フィールドを指定した絞り込み・整形が簡単に書ける(以下の例では`grep`版と`jq`版を併記する)。AWSデプロイ後のログ収集基盤(CloudWatch Logs等)での検索方法は、AWS本番デプロイのIssueで別途整理する。

## ログの読み方

### 1. 特定のリクエストを1件だけ追う(traceId)

1リクエストの処理の流れを、関連する全ログ行(業務イベント・例外・アクセスログ)で時系列に確認したい場合、そのリクエストの`traceId`で検索する。

```bash
grep '"traceId":"af4606d6-c5df-45b8-b881-57ebb69164e0"' storage/logs/laravel.log
```

1リクエストにつき、最後に必ずアクセスログ(`"message":"http request completed"`)が1行出力される。業務イベントや例外が発生していれば、それより前に同じ`traceId`で記録されている。

```
{"message":"participation rejected","context":{"traceId":"af4606d6-...","userId":7,"eventId":4,"reason":"full"},"level_name":"WARNING",...}
{"message":"http request completed","context":{"traceId":"af4606d6-...","userId":7,"httpStatus":200,"method":"POST","endpoint":"/livewire/update","durationMs":1082},"level_name":"INFO",...}
```

`traceId`はレスポンスヘッダー`X-Trace-Id`でも返している。自分で再現できる不具合であれば、ブラウザの開発者ツール(Networkタブ)で該当リクエストのレスポンスヘッダーから`traceId`を拾い、そのままログを検索できる。

画面上のボタン操作(ログイン・参加申込み等)は、アクセスログ上はすべて`POST /livewire/update`になる。どの操作だったかは、同じ`traceId`の業務イベントのログ(上記の例では`participation rejected`)で判断する。

### 2. 特定ユーザーの操作を追う(userId)

「あるユーザーの操作でエラーが起きた」といった調査では、`userId`で絞り込む。`userId`は数値で出力されるため、`"userId":7`で検索すると`"userId":70`等にも部分一致してしまう。区切りの`,`/`}`まで含めて検索するか、`jq`を使う。

```bash
grep -E '"userId":7[,}]' storage/logs/laravel.log

jq -c 'select(.context.userId == 7) | {datetime, message, traceId: .context.traceId}' storage/logs/laravel.log
```

未ログイン状態のリクエスト(ログイン画面の表示・ログイン失敗等)には`userId`自体が含まれない。ログイン失敗を特定アカウントで調べる場合は`targetUserId`で検索する(次節)。

### 3. エラー・警告だけを抽出する

```bash
# WARNING/ERRORレベルのログのみ抽出
grep -E '"level_name":"(WARNING|ERROR)"' storage/logs/laravel.log

# 予期しないエラー(ERROR)の例外クラス・メッセージだけを一覧する
jq -c 'select(.level_name == "ERROR") | {datetime, message, class: .context.exception.class, traceId: .context.traceId}' storage/logs/laravel.log

# 4xx/5xxのアクセスログのみ抽出
grep -E '"httpStatus":[45][0-9]{2}' storage/logs/laravel.log

# ログの種類(message)ごとの件数を集計する
grep -o '"message":"[^"]*"' storage/logs/laravel.log | sort | uniq -c | sort -rn
```

### 4. 業務イベントを調べる

```bash
# 参加申込みが拒否された理由の内訳
jq -r 'select(.message == "participation rejected") | .context.reason' storage/logs/laravel.log | sort | uniq -c

# 特定イベント(ID=4)への申込み・取消しの履歴
jq -c 'select((.message | startswith("participation")) and .context.eventId == 4) | {datetime, message, userId: .context.userId, reason: .context.reason}' storage/logs/laravel.log

# 特定アカウント(ID=7)へのログイン失敗(総当たりの疑いがないか)
jq -c 'select(.message == "login failed" and .context.targetUserId == 7) | {datetime, ip: .context.ip}' storage/logs/laravel.log

# 遅いリクエスト(1秒以上)
jq -c 'select(.message == "http request completed" and .context.durationMs >= 1000) | {datetime, endpoint: .context.endpoint, durationMs: .context.durationMs, traceId: .context.traceId}' storage/logs/laravel.log
```

## よくあるエラーパターンと確認すべきログ項目

| 症状 | まず確認するログ | 典型的な原因 |
|---|---|---|
| 「参加申込みできない」という問い合わせ | 該当ユーザーの`participation rejected`の`reason` | `full`(定員到達)・`ended`(開催日時超過)なら仕様通り。`already_joined`は二重送信等 |
| ログインできない | `login failed`/`login locked out`(`targetUserId`・`ip`) | パスワード誤り、5回失敗によるレート制限(一定時間で解除される) |
| 特定画面が403になる | `authorization denied`と同じ`traceId`のアクセスログの`endpoint`、`userId`と対象リソースの所有者が一致するか | 他人のイベント・コメントの編集/削除、主催者以外による参加者一覧の閲覧 |
| 419(Page Expired)になる | アクセスログの`httpStatus`が419 | セッション切れ・CSRFトークンの不一致(長時間放置した画面からの操作) |
| レスポンスが遅い | アクセスログの`durationMs`の分布、同じ`endpoint`での傾向 | N+1クエリ、DBのロック待ち(参加申込みの悲観ロック等) |
| 原因不明の500 | `ERROR`レベルのログの`context.exception`(クラス・メッセージ・スタックトレース) | 実装バグ、想定外のnull、DB接続エラー・制約違反 |
| ALBのヘルスチェックが失敗する | `endpoint`が`/health`で`httpStatus`が503のアクセスログ。ローカルでは`/health/details`で失敗したチェックを確認する | DB接続障害、キャッシュ(DB)の読み書き失敗 |

## 簡易インシデント対応の流れ

個人開発規模を想定し、大掛かりな体制を組まずに1人で完結できる範囲の流れとする。

1. **気づく**: ユーザー(自分)からの報告、ALBのヘルスチェック失敗、または`ERROR`レベルのログ・`WARNING`の急増を定期的に(手動で)確認して気づく
2. **範囲を特定する**: 「特定ユーザーだけか、全員か」「特定の操作だけか、複数か」「いつから発生しているか」を、`userId`/`message`/`endpoint`/`datetime`でログを絞り込んで把握する
3. **原因ログを1件特定する**: 該当する時間帯のログから`traceId`を1つ拾い、そのtraceIdで関連ログ全体(上記「1. 特定のリクエストを1件だけ追う」参照)を確認する
4. **原因を切り分ける**: 上表の「よくあるエラーパターン」を参考にする。`WARNING`(想定内の拒否・4xx)なら仕様通りの可能性が高く、`ERROR`(予期しない例外)なら実装バグやインフラ障害を疑う
5. **一時対応と恒久対応を分ける**: 影響が大きい場合はまず一時対応(該当機能の無効化、再起動・再デプロイ等)を検討し、その後コードを修正して恒久対応する
6. **再発防止**: 原因がコードの不具合であれば、再発を防ぐテストを追加してから修正をリリースする

## 将来、外部監視ツールを導入する場合の作業概要(参考情報)

現時点では、外部監視ツール(Datadog等)との連携は対象外とし、ログの構造化(JSON化)までを実施している。将来導入する場合、概ね以下の作業が必要になる見込み。

- **ログ収集**: ECS Fargateでは標準出力(stderr)へのJSON出力に切り替え、CloudWatch Logs(awslogsドライバー)またはFireLens経由で転送する(`config/logging.php`に`stderr`へJSONで出力するチャンネルを追加する)
- **ログのパース設定**: 現状のJSON構造(`datetime`, `level_name`, `context.traceId`, `context.userId`等)を監視ツール側でどう解釈させるかの設定。フィールド名(`datetime`→`@timestamp`等)のマッピング調整が必要になることがある
- **分散トレーシングへの拡張**: 現状の`traceId`はリクエスト単位のシンプルなUUIDで、W3C Trace Context等の標準には準拠していない。複数サービスにまたがるトレーシングが必要になった場合は、OpenTelemetry等の導入を検討する
- **アラート設定**: `ERROR`ログの発生、5xxエラー率、`durationMs`の閾値超過、ヘルスチェック失敗に対するアラート・ダッシュボードの構築
- **コスト管理**: ログ量に応じた課金が発生するため、保持期間・出力レベル(`LOG_LEVEL`)の設計

これらは全て本ドキュメントの対象外であり、実際に導入する際に個別のIssueとして要件・設計を詰める。
