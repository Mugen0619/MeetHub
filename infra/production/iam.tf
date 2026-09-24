data "aws_iam_policy_document" "ecs_tasks_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

# ---------------------------------------------------------------------------
# タスク実行ロール: ECSエージェントがコンテナを起動するために使う
# (ECRからのイメージ取得・CloudWatch Logsへの出力・Secrets Managerからの秘密情報の取得)
resource "aws_iam_role" "ecs_task_execution" {
  name               = "${var.project_name}-ecs-task-execution-role"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
}

resource "aws_iam_role_policy_attachment" "ecs_task_execution_managed" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

data "aws_iam_policy_document" "ecs_task_execution_secrets" {
  statement {
    effect  = "Allow"
    actions = ["secretsmanager:GetSecretValue"]
    resources = [
      aws_secretsmanager_secret.app_key.arn,
      aws_secretsmanager_secret.db_password.arn,
    ]
  }
}

resource "aws_iam_role_policy" "ecs_task_execution_secrets" {
  name   = "${var.project_name}-ecs-task-execution-secrets"
  role   = aws_iam_role.ecs_task_execution.id
  policy = data.aws_iam_policy_document.ecs_task_execution_secrets.json
}

# ---------------------------------------------------------------------------
# タスクロール: アプリケーション(Laravel)自身が使う。
# S3へのアクセスはIAMユーザーのアクセスキーではなく、このロールの一時的な認証情報で行う
# (AWS_ACCESS_KEY_IDを設定しない場合、AWS SDKがタスクロールの認証情報を自動で取得する)。
# 長期間有効なアクセスキーを発行・保管・ローテーションする必要がない
resource "aws_iam_role" "ecs_task" {
  name               = "${var.project_name}-ecs-task-role"
  assume_role_policy = data.aws_iam_policy_document.ecs_tasks_assume_role.json
}

data "aws_iam_policy_document" "ecs_task_images" {
  # 画像の一時アップロード(署名付きURLの発行)・プレビュー・保存確定時のコピー・削除
  statement {
    sid       = "ImageObjects"
    effect    = "Allow"
    actions   = ["s3:PutObject", "s3:GetObject", "s3:DeleteObject"]
    resources = ["${aws_s3_bucket.images.arn}/*"]
  }

  # ファイルの存在確認(存在しないキーへのGetObjectが403ではなく404になるようにする)
  statement {
    sid       = "ListImagesBucket"
    effect    = "Allow"
    actions   = ["s3:ListBucket"]
    resources = [aws_s3_bucket.images.arn]
  }
}

resource "aws_iam_role_policy" "ecs_task_images" {
  name   = "${var.project_name}-ecs-task-images"
  role   = aws_iam_role.ecs_task.id
  policy = data.aws_iam_policy_document.ecs_task_images.json
}

# ECS Exec(aws ecs execute-command)で、稼働中のコンテナに入って artisan コマンド等を実行できるようにする(運用・調査用)
data "aws_iam_policy_document" "ecs_task_exec_command" {
  statement {
    effect = "Allow"
    actions = [
      "ssmmessages:CreateControlChannel",
      "ssmmessages:CreateDataChannel",
      "ssmmessages:OpenControlChannel",
      "ssmmessages:OpenDataChannel",
    ]
    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "ecs_task_exec_command" {
  name   = "${var.project_name}-ecs-task-exec-command"
  role   = aws_iam_role.ecs_task.id
  policy = data.aws_iam_policy_document.ecs_task_exec_command.json
}
