# アプリの秘密情報はSecrets Managerに保存し、ECSタスクの起動時に環境変数として注入する(タスク定義に値を書かない)。
# 学習用途のため、destroy後すぐに同名で作り直せるよう、削除の猶予期間は0日にしている

# Laravelの暗号化キー(APP_KEY)。32バイトのランダム値を「base64:」形式で渡す
resource "random_bytes" "app_key" {
  length = 32
}

resource "aws_secretsmanager_secret" "app_key" {
  name                    = "${var.project_name}/app-key"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = "base64:${random_bytes.app_key.base64}"
}

resource "aws_secretsmanager_secret" "db_password" {
  name                    = "${var.project_name}/db-password"
  recovery_window_in_days = 0
}

resource "aws_secretsmanager_secret_version" "db_password" {
  secret_id     = aws_secretsmanager_secret.db_password.id
  secret_string = random_password.db.result
}
