resource "aws_lb" "main" {
  name               = "${var.project_name}-alb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id

  tags = { Name = "${var.project_name}-alb" }
}

resource "aws_lb_target_group" "app" {
  name        = "${var.project_name}-app-tg"
  port        = var.container_port
  protocol    = "HTTP"
  vpc_id      = aws_vpc.main.id
  target_type = "ip"

  # 旧タスクを外す際、処理中のリクエストの完了を待つ時間(既定の300秒は個人開発規模には長いため短縮する)
  deregistration_delay = 30

  # /health はDB等の外部依存を確認しない軽量なチェック(LivenessController)。
  # RDSの障害時にタスクがunhealthyと判定されて入れ替えが繰り返されないようにしている(docs/observability.md)
  health_check {
    path                = "/health"
    matcher             = "200"
    interval            = 30
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = { Name = "${var.project_name}-app-tg" }
}

resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }
}
