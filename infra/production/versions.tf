terraform {
  required_version = "~> 1.15"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }
}

provider "aws" {
  region = var.aws_region

  # 全リソースに共通のタグ(コスト・リソースの識別用)
  default_tags {
    tags = {
      Project = var.project_name
    }
  }
}
