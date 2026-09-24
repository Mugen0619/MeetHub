resource "aws_ecr_repository" "app" {
  name                 = "${var.project_name}-app"
  image_tag_mutability = "IMMUTABLE" # 同じタグ(コミットハッシュ)で別のイメージを上書きできないようにする
  force_delete         = true        # 学習用途のため、イメージが残っていてもdestroyできるようにする

  image_scanning_configuration {
    scan_on_push = true
  }

  tags = { Name = "${var.project_name}-app" }
}

resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "直近10世代のみ保持する"
        selection = {
          tagStatus   = "any"
          countType   = "imageCountMoreThan"
          countNumber = 10
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}
