resource "random_password" "db" {
  length  = 32
  special = false
}

resource "aws_db_subnet_group" "main" {
  name       = "${var.project_name}-db-subnet-group"
  subnet_ids = aws_subnet.private[*].id

  tags = { Name = "${var.project_name}-db-subnet-group" }
}

resource "aws_db_instance" "main" {
  identifier     = "${var.project_name}-db"
  engine         = "postgres"
  engine_version = "17" # 17系の最新マイナーバージョン(ローカル開発・CIと同じメジャーバージョン)

  instance_class        = var.db_instance_class
  allocated_storage     = var.db_allocated_storage_gb
  storage_type          = "gp3"
  storage_encrypted     = true
  copy_tags_to_snapshot = true

  db_name  = var.db_name
  username = var.db_username
  password = random_password.db.result

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  publicly_accessible    = false

  # Single-AZ(個人開発規模の学習目的のため、可用性よりコストを優先した判断)
  multi_az = false

  backup_retention_period = 1

  # 学習用途のため、最終スナップショットを作らずにdestroyできるようにする(本番運用では要見直し)
  skip_final_snapshot = true
  deletion_protection = false

  tags = { Name = "${var.project_name}-db" }
}
