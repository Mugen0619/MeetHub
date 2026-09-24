# ブラウザ向けのHTTPSの入口。独自ドメイン・ACM証明書は対象外のため、CloudFrontのデフォルトドメイン
# (xxxx.cloudfront.net)と、その証明書でHTTPS化する。
# オリジンはALBのみ(画面もLivewireの通信も、ECS上のLaravelアプリ1つが処理する)。
# CloudFront → ALB はHTTP。ALBのセキュリティグループでCloudFrontからの接続のみ許可している(security_groups.tf)

# AWSマネージドのキャッシュポリシー/オリジンリクエストポリシー
data "aws_cloudfront_cache_policy" "caching_disabled" {
  name = "Managed-CachingDisabled"
}

data "aws_cloudfront_cache_policy" "caching_optimized" {
  name = "Managed-CachingOptimized"
}

data "aws_cloudfront_origin_request_policy" "all_viewer" {
  name = "Managed-AllViewer"
}

resource "aws_cloudfront_distribution" "main" {
  enabled     = true
  comment     = "${var.project_name} (ALB origin)"
  price_class = "PriceClass_200" # 日本を含む地域のエッジを使う(最安のPriceClass_100には日本が含まれない)

  origin {
    domain_name = aws_lb.main.dns_name
    origin_id   = "alb"

    custom_origin_config {
      http_port              = 80
      https_port             = 443
      origin_protocol_policy = "http-only"
      origin_ssl_protocols   = ["TLSv1.2"]
    }
  }

  # 画面・Livewireの通信(POST /livewire/update)等。ログイン状態によって内容が変わるためキャッシュせず、
  # Cookie・ヘッダー(Host含む)・クエリ文字列をすべてオリジンへ転送する
  default_cache_behavior {
    target_origin_id         = "alb"
    viewer_protocol_policy   = "redirect-to-https"
    allowed_methods          = ["DELETE", "GET", "HEAD", "OPTIONS", "PATCH", "POST", "PUT"]
    cached_methods           = ["GET", "HEAD"]
    cache_policy_id          = data.aws_cloudfront_cache_policy.caching_disabled.id
    origin_request_policy_id = data.aws_cloudfront_origin_request_policy.all_viewer.id
    compress                 = true
  }

  # Viteのビルド成果物(CSS/JS)。ファイル名にハッシュを含み内容が変わらないため、エッジでキャッシュする
  ordered_cache_behavior {
    path_pattern           = "/build/*"
    target_origin_id       = "alb"
    viewer_protocol_policy = "redirect-to-https"
    allowed_methods        = ["GET", "HEAD"]
    cached_methods         = ["GET", "HEAD"]
    cache_policy_id        = data.aws_cloudfront_cache_policy.caching_optimized.id
    compress               = true
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  viewer_certificate {
    cloudfront_default_certificate = true
  }

  tags = { Name = "${var.project_name}-cloudfront" }
}
