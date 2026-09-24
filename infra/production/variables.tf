variable "aws_region" {
  description = "AWSリージョン"
  type        = string
  default     = "ap-northeast-1"
}

variable "project_name" {
  description = "リソース名のプレフィックスに使うプロジェクト名"
  type        = string
  default     = "meethub"
}

# RAISETIMELINE(10.1.0.0/16)等の他のVPCと重ならないCIDR
variable "vpc_cidr" {
  description = "本番環境用VPCのCIDR"
  type        = string
  default     = "10.2.0.0/16"
}

# --- RDS ---

variable "db_name" {
  type    = string
  default = "meethub"
}

variable "db_username" {
  type    = string
  default = "meethub"
}

variable "db_instance_class" {
  description = "個人開発規模の学習目的のため、最小クラスのGraviton(db.t4g.micro)を使う"
  type        = string
  default     = "db.t4g.micro"
}

variable "db_allocated_storage_gb" {
  type    = number
  default = 20
}

# --- ECS ---

variable "container_port" {
  description = "コンテナ内のNginxが待ち受けるポート(docker/production/nginx.conf)"
  type        = number
  default     = 8080
}

# サイジングの根拠は docs/infrastructure.md を参照。
# k6負荷試験で、ログイン処理(bcrypt、コスト12)が1回約200msのCPU処理でCPUバウンドになることが分かっているため、
# 最小の0.25/0.5 vCPUではなく1 vCPUとし、PHP-FPMのワーカー数もvCPUに見合った数に抑える
variable "ecs_task_cpu" {
  description = "ECSタスクのCPU(1024 = 1 vCPU)"
  type        = number
  default     = 1024
}

variable "ecs_task_memory" {
  description = "ECSタスクのメモリ(MiB)"
  type        = number
  default     = 2048
}

variable "php_fpm_max_children" {
  description = "PHP-FPMのワーカー数(pm.max_children)。CPUバウンドな処理を1 vCPUで捌くため、vCPU数の4倍程度に抑える"
  type        = number
  default     = 4
}

variable "ecs_desired_count" {
  type    = number
  default = 1
}

variable "app_image_tag" {
  description = "Terraformが登録するタスク定義のイメージタグ(Gitのコミットハッシュ)。初回構築時のみ使い、以降のデプロイはCDが行う"
  type        = string
}

# --- CD(GitHub Actions) ---

variable "github_oidc_subject_repository" {
  description = <<-EOT
    CDのワークフローを実行するGitHubリポジトリの、OIDCトークンのsubでの表記。
    このリポジトリは immutable subject(owner@オーナーID/repo@リポジトリID)が有効なため、IDを含む形式になる
    (`gh api repos/Mugen0619/MeetHub/actions/oidc/customization/sub` の sub_claim_prefix で確認できる)。
    このリポジトリのmainブランチのみデプロイ用ロールを引き受けられる
  EOT
  type        = string
  default     = "Mugen0619@169164098/MeetHub@1381513568"
}
