# ---------------------------------------------------------------------------
# GitHub ActionsからのCD(.github/workflows/cd.yml)用の認証。
# 長期間有効なアクセスキーをGitHub Secretsに置くのではなく、OIDCで発行される短命なトークンでIAMロールを引き受ける

# GitHub ActionsのOIDCプロバイダ(アカウントに1つ。既存のものは無かったため、ここで作成する)。
# AWS側でGitHubのルート証明書を検証するため、thumbprintの指定は不要
resource "aws_iam_openid_connect_provider" "github_actions" {
  url            = "https://token.actions.githubusercontent.com"
  client_id_list = ["sts.amazonaws.com"]
}

data "aws_iam_policy_document" "github_actions_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github_actions.arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    # mainブランチで動くワークフローのみ引き受けられる(PRのブランチや他のリポジトリからは不可)。
    # subはオーナー・リポジトリのIDを含む形式のため、リポジトリ名の変更や、削除後に同名で作り直されたリポジトリからも引き受けられない
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:sub"
      values   = ["repo:${var.github_oidc_subject_repository}:ref:refs/heads/main"]
    }
  }
}

resource "aws_iam_role" "github_actions_deploy" {
  name               = "${var.project_name}-github-actions-deploy"
  assume_role_policy = data.aws_iam_policy_document.github_actions_assume_role.json
}

data "aws_iam_policy_document" "github_actions_deploy" {
  # ECRへのログイン(リソースを限定できないアクション)
  statement {
    sid       = "EcrLogin"
    effect    = "Allow"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  # アプリのリポジトリへのイメージのpush(DescribeImagesは、同じコミットのイメージがpush済みかの確認に使う)
  statement {
    sid    = "EcrPushAppImage"
    effect = "Allow"
    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:BatchGetImage",
      "ecr:CompleteLayerUpload",
      "ecr:DescribeImages",
      "ecr:InitiateLayerUpload",
      "ecr:PutImage",
      "ecr:UploadLayerPart",
    ]
    resources = [aws_ecr_repository.app.arn]
  }

  # タスク定義の取得・新しいリビジョンの登録(どちらもリソースを限定できないアクション)
  statement {
    sid       = "EcsTaskDefinition"
    effect    = "Allow"
    actions   = ["ecs:DescribeTaskDefinition", "ecs:RegisterTaskDefinition"]
    resources = ["*"]
  }

  # アプリのサービスの更新・状態の確認(aws ecs wait services-stable)
  statement {
    sid       = "EcsDeployService"
    effect    = "Allow"
    actions   = ["ecs:UpdateService", "ecs:DescribeServices"]
    resources = [aws_ecs_service.app.id]
  }

  # 新しいタスク定義にタスク実行ロール・タスクロールを設定するために必要。ECSのタスクに渡す場合のみに限定する
  statement {
    sid       = "PassEcsTaskRoles"
    effect    = "Allow"
    actions   = ["iam:PassRole"]
    resources = [aws_iam_role.ecs_task_execution.arn, aws_iam_role.ecs_task.arn]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role_policy" "github_actions_deploy" {
  name   = "${var.project_name}-github-actions-deploy"
  role   = aws_iam_role.github_actions_deploy.id
  policy = data.aws_iam_policy_document.github_actions_deploy.json
}
