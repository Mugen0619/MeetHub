# インフラ構成(AWS本番環境)

[要件定義書](./requirements.md)へ戻る

要件定義書[8. 非機能要件](./requirements.md#8-非機能要件)の「AWSデプロイ(ECS Fargate)」の構成をまとめる。Terraformのコードと構築・デプロイ手順は[infra/production/README.md](../infra/production/README.md)を参照。

構成(Terraformのファイル分割、ECS Fargate + RDS + Secrets Manager、コスト優先の判断)は前回課題RAISETIMELINEの`infra/production/`を参考にした。ただしMeetHubはReact等の別フロントエンドを持たず、Livewireで画面もサーバー側の処理も1つのLaravelアプリが担うため、フロントエンド配信用のS3バケットは持たず、CloudFrontのオリジンはALB1つだけの、よりシンプルな構成になっている。

## 対象外

- 独自ドメインの取得・カスタムドメインでのHTTPS化(CloudFrontのデフォルトドメイン `xxxx.cloudfront.net` とその証明書を使う)
- CDパイプライン(GitHub Actionsによる自動デプロイ。別Issueで構築する)
- CI上でのTerraformの自動実行
- 外部監視ツール・アラートの設定

## 全体構成

```
ブラウザ
  │ HTTPS(CloudFrontのデフォルト証明書)
  ▼
CloudFront ──(/build/* のみエッジでキャッシュ)
  │ HTTP(ALBのセキュリティグループで、CloudFrontのオリジン向けIPレンジからのみ許可)
  ▼
ALB(パブリックサブネット×2AZ)
  │ HTTP :8080(ヘルスチェック: GET /health)
  ▼
ECS Fargate タスク(プライベートサブネット)
  └ appコンテナ: Nginx → PHP-FPM(Laravel 13 / Livewire)
       │                    │
       │ :5432              │ HTTPS(NAT Gateway経由)
       ▼                    ▼
     RDS PostgreSQL 17    S3(画像)・Secrets Manager・ECR・CloudWatch Logs

ブラウザ ──(署名付きURLでPUT / 画像の表示でGET)──▶ S3(画像バケット)
```

画像のアップロードは、Livewireの一時ファイルアップロード機能により、ブラウザからS3へ署名付きURLで直接PUTする(画像データがアプリを経由しない。[tech-stack.md](./tech-stack.md#ストレージ--aws連携))。保存が確定した画像(`events/`)はS3のURLで直接表示する。

## リソース一覧

| リソース | 設定 | Terraform |
|---|---|---|
| VPC | `10.2.0.0/16`。2AZにパブリック/プライベートサブネット、NAT Gateway 1つ | `vpc.tf` |
| CloudFront | オリジンはALBのみ(HTTP)。画面・Livewireの通信はキャッシュせず、Cookie・ヘッダー・クエリをすべて転送(`Managed-CachingDisabled` + `Managed-AllViewer`)。`/build/*`(ファイル名にハッシュを含むCSS/JS)のみキャッシュ(`Managed-CachingOptimized`)。`PriceClass_200`(日本のエッジを含む) | `cloudfront.tf` |
| ALB | HTTP(80)のみ。ターゲットはECSタスク(IP)の8080番、ヘルスチェックは`/health` | `alb.tf` |
| ECS Fargate | 1 vCPU / 2GB、X86_64、タスク数1。デプロイ失敗時の自動ロールバック(サーキットブレーカー)、ECS Exec有効 | `ecs.tf` |
| ECR | タグの上書き不可(コミットハッシュをタグにする)、直近10世代を保持、push時に脆弱性スキャン | `ecr.tf` |
| RDS | PostgreSQL 17、`db.t4g.micro`、gp3 20GB、Single-AZ、ストレージ暗号化、非公開、自動バックアップ1日 | `rds.tf` |
| S3(画像) | `events/*`のみ一般公開(バケットポリシー。ACLは無効)、`livewire-tmp/`は非公開で1日後に自動削除。CORSはCloudFrontのURLからのPUTのみ許可 | `s3.tf` |
| Secrets Manager | `APP_KEY`(Terraformが生成した32バイトの乱数)、DBパスワード | `secrets.tf` |
| IAM | タスク実行ロール(ECR・CloudWatch Logs・Secrets Manager)、タスクロール(画像バケットへのPut/Get/Delete/List、ECS Exec) | `iam.tf` |
| CloudWatch Logs | `/ecs/meethub-app`、14日保持 | `ecs.tf` |

## セキュリティの設計

### 通信経路の制限(セキュリティグループ)

| 対象 | 受信を許可する送信元 | 送信を許可する宛先 |
|---|---|---|
| ALB | CloudFrontのオリジン向けIPレンジ(AWSマネージドプレフィックスリスト `com.amazonaws.global.cloudfront.origin-facing`)の80番のみ | ECSタスクの8080番 |
| ECSタスク | ALBからの8080番のみ | 443番(NAT Gateway経由でAWSの各API)、RDSの5432番 |
| RDS | ECSタスクからの5432番のみ | - |

ALBはインターネットに公開されているが、CloudFront以外からの接続は拒否する。ALBのDNS名へ直接アクセスすると、CloudFront(HTTPS)を迂回して暗号化されていないHTTPで通信できてしまうため。

### S3へのアクセス(ECSタスクロール)

アプリからS3へのアクセスは、IAMユーザーのアクセスキーではなくECSタスクロールで行う。`AWS_ACCESS_KEY_ID`を設定しない場合、AWS SDKはタスクロールの一時的な認証情報を自動で取得するため、アプリのコードは変更していない。長期間有効なアクセスキーを発行・保管(Terraformのstate・Secrets Manager)・ローテーションする必要がない。RAISETIMELINEではIAMユーザーのアクセスキー方式としていた(改善点として残していた)点を、MeetHubでは最初からタスクロール方式にした。

### 秘密情報

`APP_KEY`とDBパスワードはTerraformが生成してSecrets Managerに保存し、ECSがタスクの起動時に環境変数として注入する(タスク定義には値を書かない)。Terraformのstate(`terraform.tfstate`)には生成した値が含まれるため、Git管理対象外にしている。

## CloudFront → ALB 構成でのアプリ側の対応

### クライアントIP(TrustProxies)

リクエストは ブラウザ → CloudFront → ALB → ECS と2段のプロキシを経由し、`X-Forwarded-For`は「(クライアントが送ってきた値), 閲覧者のIP, CloudFrontのIP」の順に積まれる。Laravel標準の`TrustProxies`ミドルウェアに、信頼するプロキシとしてALB(VPCのCIDR)とCloudFront(オリジン向けIPレンジ)だけを渡し(`config/trustedproxy.php`、環境変数`TRUSTED_PROXIES`)、その手前の値を閲覧者のIPとして扱う。すべてのIPを信頼すると、クライアントが偽の`X-Forwarded-For`を送るだけでIPを詐称でき、ログイン試行回数の制限(IPごと)も回避できてしまうため、信頼する範囲を明示的に絞っている。`TRUSTED_PROXIES`の値は、TerraformがVPCのCIDRとCloudFrontのマネージドプレフィックスリストから生成する。

### HTTPSのURL生成

ブラウザ ↔ CloudFront はHTTPSだが、CloudFront → ALB → ECS はHTTPで、ALBは`X-Forwarded-Proto`に自身の受信プロトコル(`http`)を設定する。そのままではLaravelがアセット・リダイレクト・Livewireの通信先のURLを`http://`で生成し、ブラウザにMixed Contentとしてブロックされる。そのため、`APP_URL`が`https://`の場合は常に`https://`でURLを生成する(`AppServiceProvider`の`URL::forceScheme('https')`)。セッションCookieも`SESSION_SECURE_COOKIE=true`でSecure属性を付ける。

## 本番用Dockerイメージ

開発用の`Dockerfile`(`php artisan serve`)とは別に、本番用の[docker/production/Dockerfile](../docker/production/Dockerfile)を用意している。

| ステージ | 内容 |
|---|---|
| `php-base` | `php:8.5-fpm`(公式イメージ)に、本番で使うPHP拡張(pdo_pgsql・bcmath・gd・intl・zip)を追加 |
| `vendor` | Composerで本番用の依存関係(`--no-dev`)をインストール |
| `assets` | `node:22-slim`でVite/Tailwind CSSのアセットをビルド(TailwindはLaravelのページネーションのビュー(vendor配下)もクラス名の抽出対象にするため、そのビューだけを`vendor`ステージからコピーする) |
| `runtime` | `php-base`にNginxを追加し、アプリのコード・vendor・ビルド済みアセットだけをコピー(Node.js・Composerは含めない) |

- **1コンテナでNginx + PHP-FPMを動かす**: 起動スクリプト([docker/production/entrypoint.sh](../docker/production/entrypoint.sh))が両方を起動し、どちらかが終了したらコンテナごと終了する(ECSが新しいタスクに入れ替える)。Nginxを別コンテナ(サイドカー)にする構成と比べ、イメージが1つで済みデプロイ(今後のCD)が単純になるため、この構成にした
- **起動時の処理**: 実行時の環境変数をもとに`php artisan optimize`(設定・ルート・ビュー・イベントのキャッシュ)を行い、`RUN_MIGRATIONS=true`の場合はマイグレーションを実行する。タスクが1つの間はこの方式とし、複数タスクを同時に起動する構成にする場合は、同時実行を避けるためデプロイ時に1回だけ実行する方式(ECSのワンオフタスク等)に切り替える(CDのIssueで検討する)
- **停止**: ECSのタスク停止(SIGTERM)を受けて、Nginx・PHP-FPMに処理中のリクエストを終えてから終了させる。ベースの`php:fpm`イメージは停止シグナルがSIGQUITのため、`STOPSIGNAL SIGTERM`で明示している
- **PHPの設定**: OPcacheはファイルの更新確認を無効化(コードはイメージ内で変わらないため)。PHP-FPMのアクセスログは出さず(Laravelがアクセスログを構造化して出すため)、ワーカーのJSONログが途中で分割されないよう1行の上限(`log_limit`)を引き上げている

## ECSタスクのサイジング(CPU・メモリ・PHP-FPMのワーカー数)

[k6負荷試験](../k6/README.md#実測結果)で、ログイン処理はパスワードのハッシュ検証(bcrypt、コスト12)に1回約200msのCPU処理がかかり、CPUを使い切る(CPUバウンド)ことが分かっている。これを踏まえ、以下のようにした。

| 項目 | 値 | 理由 |
|---|---|---|
| CPU | 1 vCPU(1024) | 最小の0.25 vCPUでは1回のログインに約0.8秒(200ms÷0.25)かかる計算になり、同時にログインが数件来るだけで数秒待たされる。個人開発規模の負荷(数十ユーザー)を捌ける最小限として1 vCPUとした |
| メモリ | 2GB | Fargateでは1 vCPUに組み合わせられる最小値。PHP-FPMのワーカー1つあたり数十MB程度で、4ワーカーなら十分に余裕がある |
| PHP-FPMのワーカー数 | 4(`pm = static`) | CPUバウンドな処理は、ワーカーを増やしてもvCPU数以上には並列に処理できず、待ち時間が各ワーカーに分散して全体が遅くなるだけになる。一方、通常の画面表示はDBの応答待ちの時間もあるため、vCPU数ちょうどではCPUが遊ぶ。その間をとってvCPU数の4倍程度に抑えた。タスク定義の環境変数`PHP_FPM_MAX_CHILDREN`で変更できる |
| タスク数 | 1 | 個人開発規模・学習目的のため、冗長化よりコストを優先した |

## 本番の環境変数

ECSタスク定義で設定する(`infra/production/ecs.tf`の`app_environment`)。ローカル開発の`.env`との主な違いは以下のとおり。

| 変数 | 本番の値 | 備考 |
|---|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | エラー画面に詳細を出さない |
| `APP_URL` | `https://<CloudFrontのドメイン>` | `https://`のため、URLは常にhttpsで生成される |
| `APP_KEY` | (Secrets Manager) | |
| `LOG_CHANNEL` / `LOG_LEVEL` | `json_stderr` / `info` | 構造化ログを標準エラー出力へ出し、awslogsドライバでCloudWatch Logsに集約する([observability.md](./observability.md)) |
| `DB_HOST`等 / `DB_PASSWORD` | RDSのエンドポイント / (Secrets Manager) | RDS(PostgreSQL 17)は既定でSSL接続を必須とするが、Laravelの既定(`sslmode=prefer`)でSSL接続される |
| `SESSION_DRIVER` / `SESSION_SECURE_COOKIE` | `database` / `true` | |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `database` / `sync` | キューで非同期に処理するジョブはないため`sync` |
| `MAIL_MAILER` | `log` | メール送信(パスワードリセット等)は対象外のため、送信内容はログに出す |
| `FILESYSTEM_DISK` / `AWS_BUCKET` / `AWS_DEFAULT_REGION` | `s3` / 画像バケット名 / `ap-northeast-1` | `AWS_ACCESS_KEY_ID`等は設定しない(タスクロールを使う) |
| `TRUSTED_PROXIES` | VPCのCIDR + CloudFrontのIPレンジ | `config/trustedproxy.php` |
| `PHP_FPM_MAX_CHILDREN` | `4` | |
| `RUN_MIGRATIONS` | `true` | コンテナ起動時にマイグレーションを実行する |
| `HEALTH_EXPOSE_DETAILS` | (設定しない) | `/health/details`は404になる |

## ヘルスチェック

ALBのヘルスチェックは`GET /health`(30秒間隔、2回連続で成功したらhealthy、3回連続で失敗したらunhealthy)。`/health`はNginx → PHP-FPM → Laravelが応答できることだけを確認し、DB等の外部依存は確認しない。DB接続を含めると、RDSの障害時に全タスクがunhealthyと判定され、ECSがタスクの入れ替えを繰り返してしまうため([observability.md](./observability.md#ヘルスチェック))。ECSサービスには起動直後の猶予期間(60秒)を設定し、設定のキャッシュ・マイグレーション中に落とされないようにしている。

## ログ

アプリのログ(Laravelの構造化ログ)と、Nginx・PHP-FPMのエラーログは、コンテナの標準エラー出力からawslogsドライバでCloudWatch Logsの`/ecs/meethub-app`に送られる(14日保持)。ログの読み方・検索方法は[operations-guide.md](./operations-guide.md)を参照。

## 動作確認(初回デプロイ、2026-09-24)

本番URL(CloudFrontのドメイン)に対して、実際のブラウザ(Playwrightで操作するChromium)と`curl`で以下を確認した。

| 確認項目 | 結果 |
|---|---|
| 新規登録 → ログイン(別セッションで再ログイン) | ✅ |
| イベント作成(画像アップロードあり) | ✅ 画像はブラウザからS3の`livewire-tmp/`へ署名付きURLで直接アップロードされ、プレビュー表示(署名付きURLでの読み出し)→ 保存時に`events/`へコピーされ、詳細画面で表示された |
| 別ユーザーで参加申込み → マイ参加予定一覧 | ✅ 参加人数 1 / 定員1 |
| ブラウザのコンソールエラー・4xx/5xxの通信 | なし |
| `GET /health` / `GET /health/details` | 200 `{"healthy":true}` / 404(詳細は非公開) |
| HTTPでのアクセス | CloudFrontがHTTPSへリダイレクト(301) |
| ALBのDNS名への直接アクセス | 接続できない(セキュリティグループでCloudFront以外を拒否) |
| `/build/*`のキャッシュ | 2回目以降はCloudFrontのエッジから返る(`X-Cache: Hit from cloudfront`) |
| 構造化ログ | CloudWatch Logsに1行1JSONで出力され、`traceId`(レスポンスヘッダー`X-Trace-Id`と一致)・`userId`が付く。Logs Insightsで`context.userId`等のフィールドで集計できた |
| クライアントIP(TrustProxies) | ログイン失敗のログの`ip`が、確認に使った端末のグローバルIPと一致した(CloudFront・ALBのIPではない) |
| 起動時のマイグレーション | コンテナ起動時にRDSへ全マイグレーションが適用された |

## 費用(東京リージョン、概算)

| 項目 | 月額の目安 |
|---|---|
| NAT Gateway(1つ) | 約$45 |
| ECS Fargate(1 vCPU / 2GB × 1タスク) | 約$45 |
| RDS(`db.t4g.micro` + gp3 20GB) | 約$22 |
| ALB | 約$18 |
| パブリックIPv4アドレス(NAT Gateway・ALB) | 約$11 |
| Secrets Manager・CloudFront・S3・CloudWatch Logs・ECR | 数ドル |
| **合計** | **約$140(1日あたり約$4.7)** |

個人開発規模・学習目的のため、以下はコスト(と構成の単純さ)を優先した判断とした。

- NAT Gatewayは1つのみ(AZ障害時の冗長性を持たない)。VPCエンドポイントで代替する案は、RAISETIMELINEで試算した結果、必要な種類を揃えるとNAT Gatewayより割高になったため採用しない
- RDSはSingle-AZ、ECSタスクは1つ
- 課題の確認が終わったら`terraform destroy`で削除する前提([infra/production/README.md](../infra/production/README.md#削除))

## 今後の課題

- **CD(自動デプロイ)**: GitHub ActionsからイメージのビルドとECRへのpush、ECSサービスの更新を行う(別Issue)。あわせて、マイグレーションの実行方式(起動時 → デプロイ時に1回)と、Terraformとデプロイで更新するタスク定義の管理の分担を決める
- **Terraformのstateの管理**: 現在はローカルファイル。CDやチームでの運用を行う場合は、S3バックエンド(+ロック)に移行する
