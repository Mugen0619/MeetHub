# 画像(イベント画像・ユーザーのアイコン)の保存用S3バケット(docs/tech-stack.md「ストレージ / AWS連携」)。
#   - livewire-tmp/: ブラウザが署名付きURLで直接アップロードする一時ファイル(非公開。1日で自動削除)
#   - events/      : 保存が確定したイベント画像(一般公開。画面の<img>から直接読み込む)
#   - avatars/     : 保存が確定したユーザーのアイコン(一般公開。同上)
# バケット名はグローバルに一意である必要があるため、ランダムなサフィックスを付与する
resource "random_id" "images_bucket_suffix" {
  byte_length = 4
}

resource "aws_s3_bucket" "images" {
  bucket        = "${var.project_name}-images-${random_id.images_bucket_suffix.hex}"
  force_destroy = true # 学習用途のため、画像が残っていてもdestroyできるようにする

  tags = { Name = "${var.project_name}-images" }
}

# ACLによる公開は禁止しつつ、バケットポリシーによる公開(events/・avatars/のみ)は許可する
resource "aws_s3_bucket_public_access_block" "images" {
  bucket = aws_s3_bucket.images.id

  block_public_acls       = true
  ignore_public_acls      = true
  block_public_policy     = false
  restrict_public_buckets = false
}

data "aws_iam_policy_document" "images_public_read" {
  statement {
    sid     = "PublicReadImages"
    effect  = "Allow"
    actions = ["s3:GetObject"]
    resources = [
      "${aws_s3_bucket.images.arn}/events/*",
      "${aws_s3_bucket.images.arn}/avatars/*",
    ]

    principals {
      type        = "AWS"
      identifiers = ["*"]
    }
  }
}

resource "aws_s3_bucket_policy" "images_public_read" {
  bucket = aws_s3_bucket.images.id
  policy = data.aws_iam_policy_document.images_public_read.json

  depends_on = [aws_s3_bucket_public_access_block.images]
}

# ブラウザ(CloudFrontの本番URLで表示している画面)からの、署名付きURLによる直接アップロード(PUT)を許可する
resource "aws_s3_bucket_cors_configuration" "images" {
  bucket = aws_s3_bucket.images.id

  cors_rule {
    allowed_methods = ["PUT"]
    allowed_origins = ["https://${aws_cloudfront_distribution.main.domain_name}"]
    allowed_headers = ["*"]
    expose_headers  = ["ETag"]
    max_age_seconds = 3000
  }
}

# 保存されなかった一時ファイル(フォームを送信せずに離脱した場合等)を自動削除する
resource "aws_s3_bucket_lifecycle_configuration" "images" {
  bucket = aws_s3_bucket.images.id

  rule {
    id     = "expire-livewire-tmp"
    status = "Enabled"

    filter {
      prefix = "livewire-tmp/"
    }

    expiration {
      days = 1
    }
  }
}
