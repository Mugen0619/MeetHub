# syntax=docker/dockerfile:1
#
# 本番(ECS Fargate)用のDockerイメージ。docs/infrastructure.md 参照。
# 開発用のリポジトリ直下のDockerfile(php artisan serve)とは別物で、Nginx + PHP-FPMの構成で動かす。
#
#   docker build -f docker/production/Dockerfile -t meethub-app .
#
# マルチステージビルド:
#   1. php-base: 本番で使うPHP拡張を入れたPHP-FPMの土台
#   2. vendor  : Composerで本番用の依存関係(require-devを除く)をインストール
#   3. assets  : Node.jsでフロントエンドアセット(Vite/Tailwind CSS)をビルド
#   4. runtime : 1にNginxを加え、2・3の成果物だけをコピーした実行イメージ(Node.js・Composerは含めない)

# ---------------------------------------------------------------------------
FROM php:8.5-fpm AS php-base

# PHP拡張のインストールには、ビルド用ライブラリの導入・後片付けまで自動で行う install-php-extensions を使う
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql bcmath gd intl zip

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# 依存関係の定義だけを先にコピーし、コードの変更だけではこのレイヤーのキャッシュが無効にならないようにする
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------
FROM node:22-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
# Tailwind CSSはページネーションのビュー(vendor配下)もクラス名の抽出対象にしている
COPY --from=vendor /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
RUN npm run build

# ---------------------------------------------------------------------------
FROM php-base AS runtime

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends nginx \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/production/php.ini /usr/local/etc/php/conf.d/zz-meethub.ini
COPY docker/production/php-fpm.conf /usr/local/etc/php-fpm.d/zz-meethub.conf
COPY docker/production/nginx.conf /etc/nginx/conf.d/meethub.conf
COPY docker/production/entrypoint.sh /usr/local/bin/meethub-entrypoint

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN chmod +x /usr/local/bin/meethub-entrypoint

# PHP-FPMのワーカー数(pm.max_children)。ECSタスクのCPUに合わせて、タスク定義の環境変数で上書きする(infra/production)
ENV PHP_FPM_MAX_CHILDREN=4

EXPOSE 8080

# ベースのphp:fpmイメージはSIGQUITで停止する設定になっているが、ECSのタスク停止(SIGTERM)に合わせて明示する。
# 起動スクリプトがSIGTERMを受けてNginx・PHP-FPMを処理中のリクエストを終えてから終了させる
STOPSIGNAL SIGTERM

ENTRYPOINT ["/usr/local/bin/meethub-entrypoint"]
