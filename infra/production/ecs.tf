resource "aws_ecs_cluster" "main" {
  name = "${var.project_name}-cluster"

  tags = { Name = "${var.project_name}-cluster" }
}

# アプリのログ(Laravelの構造化ログ・Nginx/PHP-FPMのエラーログ)の出力先
resource "aws_cloudwatch_log_group" "app" {
  name              = "/ecs/${var.project_name}-app"
  retention_in_days = 14
}

locals {
  app_url = "https://${aws_cloudfront_distribution.main.domain_name}"

  # 信頼するプロキシ: ALB(VPC内)とCloudFront(オリジン向けIPレンジ)。config/trustedproxy.php 参照
  trusted_proxies = join(",", concat(
    [var.vpc_cidr],
    [for entry in data.aws_ec2_managed_prefix_list.cloudfront_origin_facing.entries : entry.cidr],
  ))

  # 本番の環境変数(docs/infrastructure.md「環境変数」)。秘密情報はsecrets(Secrets Manager)で渡す
  app_environment = {
    APP_NAME  = "MeetHub"
    APP_ENV   = "production"
    APP_DEBUG = "false"
    APP_URL   = local.app_url

    # 構造化ログを標準エラー出力へ(awslogsドライバでCloudWatch Logsへ送る)。本番はDEBUGを出さない
    LOG_CHANNEL              = "json_stderr"
    LOG_LEVEL                = "info"
    LOG_DEPRECATIONS_CHANNEL = "null"

    DB_CONNECTION = "pgsql"
    DB_HOST       = aws_db_instance.main.address
    DB_PORT       = tostring(aws_db_instance.main.port)
    DB_DATABASE   = var.db_name
    DB_USERNAME   = var.db_username

    SESSION_DRIVER        = "database"
    SESSION_SECURE_COOKIE = "true" # ブラウザとの通信はCloudFrontのHTTPSのみのため、Cookieは常にSecure属性付きにする
    CACHE_STORE           = "database"
    QUEUE_CONNECTION      = "sync"
    MAIL_MAILER           = "log" # メール送信(パスワードリセット等)は対象外のため、送信内容はログに出す

    # 画像はS3に保存する。認証情報はタスクロールから自動取得するため、アクセスキーは設定しない
    FILESYSTEM_DISK    = "s3"
    AWS_DEFAULT_REGION = var.aws_region
    AWS_BUCKET         = aws_s3_bucket.images.bucket

    TRUSTED_PROXIES      = local.trusted_proxies
    PHP_FPM_MAX_CHILDREN = tostring(var.php_fpm_max_children)

    # タスクが1つの間は、コンテナ起動時にマイグレーションを実行する(docker/production/entrypoint.sh)
    RUN_MIGRATIONS = "true"
  }
}

resource "aws_ecs_task_definition" "app" {
  family                   = "${var.project_name}-app"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.ecs_task_cpu
  memory                   = var.ecs_task_memory
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  task_role_arn            = aws_iam_role.ecs_task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([
    {
      name      = "app"
      image     = "${aws_ecr_repository.app.repository_url}:${var.app_image_tag}"
      essential = true

      portMappings = [
        {
          containerPort = var.container_port
          protocol      = "tcp"
        }
      ]

      environment = [for name, value in local.app_environment : { name = name, value = value }]

      secrets = [
        { name = "APP_KEY", valueFrom = aws_secretsmanager_secret.app_key.arn },
        { name = "DB_PASSWORD", valueFrom = aws_secretsmanager_secret.db_password.arn },
      ]

      # ECS Execでコンテナに入れるようにする
      linuxParameters = {
        initProcessEnabled = true
      }

      stopTimeout = 30

      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.app.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "app"
        }
      }
    }
  ])

  tags = { Name = "${var.project_name}-app" }
}

resource "aws_ecs_service" "app" {
  name            = "${var.project_name}-app"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = var.ecs_desired_count
  launch_type     = "FARGATE"

  # 起動直後(設定のキャッシュ・マイグレーション中)にALBのヘルスチェックで落とされないための猶予
  health_check_grace_period_seconds = 60

  enable_execute_command = true

  # 新しいタスクが起動できない(ヘルスチェックに通らない)デプロイは自動で失敗とし、直前の正常なタスク定義に戻す
  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  network_configuration {
    subnets          = aws_subnet.private[*].id
    security_groups  = [aws_security_group.ecs.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "app"
    container_port   = var.container_port
  }

  depends_on = [aws_lb_listener.http]

  tags = { Name = "${var.project_name}-app" }
}
