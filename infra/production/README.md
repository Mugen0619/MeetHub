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
| `github_oidc.tf` | CD(GitHub Actions)用のOIDCプロバイダ・デプロイ用ロール |
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

[手動デプロイ](#手動デプロイcdが使えない場合)の1〜2と同じ。

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

### 5. CDで使う値をGitHubのリポジトリ変数に登録する

[CD(自動デプロイ)](#cd自動デプロイ)の`gh variable set`を実行する。以降のデプロイはmainへのマージで自動的に行われる。

## デプロイ

### CD(自動デプロイ)

mainへのマージ後、CIが成功すると[CDワークフロー](../../.github/workflows/cd.yml)が自動で本番へデプロイする(設計の判断は[docs/infrastructure.md](../../docs/infrastructure.md#cd継続的デプロイ)を参照)。

1. GitHub ActionsのOIDCで、デプロイ用ロール(`meethub-github-actions-deploy`)を引き受ける
2. CIで検証したコミットから本番用イメージをビルドし、コミットハッシュ(先頭12桁)をタグにしてECRへpushする(push済みなら省略)
3. タスク定義`meethub-app`の最新リビジョンを取得し、イメージだけ差し替えた新しいリビジョンを登録する
4. ECSサービスを新しいリビジョンに更新し、`aws ecs wait services-stable`で安定するまで待つ
5. 稼働中のリビジョンがデプロイしたものと一致することを確認する(サーキットブレーカーでロールバックされた場合はジョブが失敗する)

ワークフローが使う値は、GitHubのリポジトリ変数に登録している(Terraformで作り直した場合は再登録する)。

```bash
TF="terraform -chdir=infra/production output -raw"
gh variable set AWS_REGION --body ap-northeast-1
gh variable set AWS_DEPLOY_ROLE_ARN --body "$($TF github_actions_deploy_role_arn)"
gh variable set ECR_REPOSITORY_URL --body "$($TF ecr_repository_url)"
gh variable set ECS_CLUSTER --body "$($TF ecs_cluster_name)"
gh variable set ECS_SERVICE --body "$($TF ecs_service_name)"
gh variable set ECS_TASK_DEFINITION_FAMILY --body meethub-app
gh variable set ECS_CONTAINER_NAME --body app
```

実行状況は`gh run list --workflow CD`、ログは`gh run view <run-id> --log`で確認できる。失敗したデプロイを同じコミットでやり直す場合は、GitHubのActions画面(または`gh run rerun <run-id>`)で再実行する。

### Terraformとの分担

ECSサービスが使うタスク定義のリビジョンはCDが切り替えるため、Terraformの差分対象から外している(`ecs.tf`の`lifecycle.ignore_changes`)。Terraformで環境変数等を変更して`apply`すると、タスク定義の新しいリビジョンが登録されるが、サービスには反映されない。**次のCD(または下記の手動デプロイ)で、最新リビジョンをもとにしたイメージの差し替えとして反映される。**

`terraform plan`/`apply`時の`-var app_image_tag`には、Terraformが管理するタスク定義のタグ(stateに記録されている値。`terraform state show aws_ecs_task_definition.app`で確認できる)をそのまま指定する。別のタグを指定すると、Terraform側のタスク定義が作り直される(サービスには影響しない)。

### ロールバック

新しいタスクがヘルスチェックに通らない場合は、デプロイのサーキットブレーカーが直前のタスク定義に自動で戻す。

ヘルスチェックには通るが不具合がある場合は、1つ前のタスク定義のリビジョンを指定してサービスを更新する(イメージはECRに直近10世代残っている)。

```bash
# 登録済みのリビジョンとイメージを確認する(新しい順)
aws ecs list-task-definitions --family-prefix meethub-app --sort DESC --max-items 5 --region ap-northeast-1
aws ecs describe-task-definition --task-definition meethub-app:<リビジョン>   --query 'taskDefinition.containerDefinitions[0].image' --output text --region ap-northeast-1

# 1つ前のリビジョンに戻す
aws ecs update-service --cluster meethub-cluster --service meethub-app   --task-definition meethub-app:<1つ前のリビジョン> --region ap-northeast-1
aws ecs wait services-stable --cluster meethub-cluster --services meethub-app --region ap-northeast-1
```

ロールバックはサービスが使うリビジョンを戻すだけで、mainのコードは戻らない。不具合の修正(またはrevert)をmainにマージすると、CDで改めてデプロイされる。マイグレーションは自動では巻き戻らないため、スキーマ変更を含むデプロイを戻す場合は、古いコードで新しいスキーマが動くかを確認する。

### 手動デプロイ(CDが使えない場合)

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

3. CDと同じ手順で、イメージを差し替えたタスク定義のリビジョンを登録し、サービスを更新する(`jq`が必要)

   ```bash
   aws ecs describe-task-definition --task-definition meethub-app --query taskDefinition --output json --region ap-northeast-1      | jq --arg image "$REPO:$TAG" '.containerDefinitions[0].image = $image
         | del(.taskDefinitionArn, .revision, .status, .requiresAttributes, .compatibilities, .registeredAt, .registeredBy)' > taskdef.json
   ARN=$(aws ecs register-task-definition --cli-input-json file://taskdef.json      --query taskDefinition.taskDefinitionArn --output text --region ap-northeast-1)
   aws ecs update-service --cluster meethub-cluster --service meethub-app --task-definition "$ARN" --region ap-northeast-1
   aws ecs wait services-stable --cluster meethub-cluster --services meethub-app --region ap-northeast-1
   rm taskdef.json
   ```

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
