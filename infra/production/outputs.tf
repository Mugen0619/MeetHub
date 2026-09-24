output "app_url" {
  description = "本番URL(CloudFront)"
  value       = local.app_url
}

output "cloudfront_distribution_id" {
  value = aws_cloudfront_distribution.main.id
}

output "alb_dns_name" {
  description = "ALBのDNS名(CloudFrontのオリジン。セキュリティグループでCloudFront以外からの接続は拒否している)"
  value       = aws_lb.main.dns_name
}

output "ecr_repository_url" {
  description = "アプリのDockerイメージのpush先"
  value       = aws_ecr_repository.app.repository_url
}

output "ecs_cluster_name" {
  value = aws_ecs_cluster.main.name
}

output "ecs_service_name" {
  value = aws_ecs_service.app.name
}

output "cloudwatch_log_group" {
  description = "アプリのログ(CloudWatch Logs)"
  value       = aws_cloudwatch_log_group.app.name
}

output "rds_endpoint" {
  description = "RDSのエンドポイント(プライベートサブネット内のみアクセス可能)"
  value       = aws_db_instance.main.address
}

output "images_bucket_name" {
  value = aws_s3_bucket.images.bucket
}
