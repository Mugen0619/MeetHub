# infra/production

MeetHubの本番環境(AWS)のTerraform構成と、構築・デプロイの手順。構成の設計と判断の理由は[docs/infrastructure.md](../../docs/infrastructure.md)を参照。

- Terraform 1.15系 / AWS provider 6系 / random provider 3系(`versions.tf`、バージョンの固定は`.terraform.lock.hcl`)
- リージョン: 東京(`ap-northeast-1`)
- stateはこのディレクトリのローカルファイル(`terraform.tfstate`)。生成した秘密情報(APP_KEY・DBパスワード)を含むため、Git管理対象外にしている

## 運用ルール

**`.tf`ファイルを作成・変更したら、`terraform apply`を実行する前に`terraform plan`の結果とファイルの内容をレビューチャットに報告し、明示的な承認を得てから`apply`する。**(RAISETIMELINEと同じ運用ルール)

## 前提

- Terraform 1.15系、AWS CLI v2、Docker がインストールされていること
- AWS CLIに、インフラ構築用の権限を持つIAMユーザーの認証情報が設定されていること(`aws sts get-caller-identity`で確認できる)

## ファイル構成

| ファイル | 内容 |
|---|---|
| `versions.tf` | Terraform・プロバイダのバージョン、共通タグ(`Project = meethub`) |
| `variables.tf` | リージョン・VPCのCIDR・RDSのクラス・ECSのCPU/メモリ・PHP-FPMのワーカー数・イメージタグ等 |
| `vpc.tf` | VPC・サブネット・インターネットゲートウェイ・NAT Gateway・ルートテーブル |
| `security_groups.tf` | ALB・ECS・RDSのセキュリティグループ(ALBはCloudFrontからのみ) |
| `alb.tf` | ALB・ターゲットグループ(ヘルスチェック `/health`)・リスナー |
| `ecr.tf` | アプリのイメージのリポジトリ |
| `ecs.tf` | クラスター・CloudWatch Logsのロググループ・タスク定義(環境変数)・サービス |
| `rds.tf` | RDS(PostgreSQL 17)・DBサブネットグループ・DBパスワードの生成 |
| `secrets.tf` | Secrets Manager(APP_KEY・DBパスワード) |
| `iam.tf` | タスク実行ロール・タスクロール(S3・ECS Exec) |
| `s3.tf` | イベント画像のバケット(公開範囲・CORS・一時ファイルの自動削除) |
| `cloudfront.tf` | CloudFront(オリジンはALB) |
| `outputs.tf` | 本番URL・ECRのURL・クラスター名等 |

## 初回構築

ECSサービスを作成する時点でECRにイメージが無いとタスクが起動に失敗するため、「ECRだけ先に作成 → イメージをpush → 残りを作成」の順に進める。以降のコマンドはリポジトリのルートで実行する(`terraform -chdir=infra/production`)。

### 1. 初期化

```bash
terraform -chdir=infra/production init
```

### 2. ECRだけ先に作成する

```bash
TAG=$(git rev-parse --short=12 HEAD)

terraform -chdir=infra/production plan -var app_image_tag=$TAG    # 全体のplanを確認・報告し、承認を得る
terraform -chdir=infra/production apply -var app_image_tag=$TAG \
  -target=aws_ecr_repository.app -target=aws_ecr_lifecycle_policy.app
```

### 3. イメージをビルドしてECRへpushする

[デプロイ手順](#デプロイ新しいバージョンを反映する)の1〜2と同じ。

### 4. 残りを作成する

```bash
terraform -chdir=infra/production plan -var app_image_tag=$TAG -out=tfplan   # 2で承認を得た内容と差分がないことを確認する
terraform -chdir=infra/production apply tfplan
```

RDS・CloudFrontの作成に10〜15分程度かかる。完了すると本番URLが出力される。

```bash
terraform -chdir=infra/production output app_url
```

ECSのタスクは起動時にマイグレーションを実行する(`RUN_MIGRATIONS=true`)。起動状況は[ログの確認](#ログの確認)で確認できる。

## デプロイ(新しいバージョンを反映する)

CDパイプライン(GitHub Actions)は別Issueで構築する。それまでは以下の手順で手動デプロイする。

1. ECRにログインする

   ```bash
   REPO=$(terraform -chdir=infra/production output -raw ecr_repository_url)
   aws ecr get-login-password --region ap-northeast-1 | docker login --username AWS --password-stdin "${REPO%%/*}"
   ```

2. コミット済みのコードから本番用イメージをビルドしてpushする(タグはコミットハッシュ。ECRのタグは上書きできない設定のため、同じタグで別のイメージをpushすることはできない)

   ```bash
   git status   # 未コミットの変更がないことを確認する
   TAG=$(git rev-parse --short=12 HEAD)
   docker build --platform linux/amd64 --provenance=false -f docker/production/Dockerfile -t "$REPO:$TAG" .
   docker push "$REPO:$TAG"
   ```

3. タスク定義のイメージタグを更新する(ECSサービスが新しいタスクを起動し、ヘルスチェックに通ったら古いタスクを停止する)

   ```bash
   terraform -chdir=infra/production plan -var app_image_tag=$TAG -out=tfplan
   terraform -chdir=infra/production apply tfplan
   aws ecs wait services-stable --cluster meethub-cluster --services meethub-app --region ap-northeast-1
   ```

新しいタスクがヘルスチェックに通らない場合は、デプロイのサーキットブレーカーが直前のタスク定義に自動で戻す。

## 動作確認

```bash
APP_URL=$(terraform -chdir=infra/production output -raw app_url)
curl -i "$APP_URL/health"          # 200 {"healthy":true}
curl -i "$APP_URL/health/details"  # 404(本番では詳細を公開しない)

# ALBへの直接アクセスは拒否される(CloudFrontを経由しない通信はできない)
curl -m 10 "http://$(terraform -chdir=infra/production output -raw alb_dns_name)/health"   # タイムアウトする
```

## ログの確認

```bash
# 直近のログを追う(Laravelの構造化ログはJSON1行)
aws logs tail /ecs/meethub-app --follow --region ap-northeast-1
```

CloudWatch Logs Insightsでの検索方法は[docs/operations-guide.md](../../docs/operations-guide.md#本番cloudwatch-logsでの検索)を参照。

## 稼働中のコンテナでコマンドを実行する(ECS Exec)

```bash
TASK=$(aws ecs list-tasks --cluster meethub-cluster --service-name meethub-app --region ap-northeast-1 --query 'taskArns[0]' --output text)
aws ecs execute-command --cluster meethub-cluster --task "$TASK" --container app --interactive \
  --command "php artisan tinker" --region ap-northeast-1
```

事前にAWS CLIのSession Manager Pluginのインストールが必要。

## 削除

```bash
terraform -chdir=infra/production destroy -var app_image_tag=$TAG
```

課題の確認が終わったら削除する前提のため、RDSは最終スナップショットを作らず、ECR・S3は中身が残っていても削除できる設定(`force_delete`・`force_destroy`)にしている。Secrets Managerも削除の猶予期間を0日にしており、削除後すぐに同じ名前で作り直せる。
