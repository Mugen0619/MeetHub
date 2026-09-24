#!/bin/bash
#
# 本番コンテナの起動スクリプト。
#   1. 実行時の環境変数(ECSタスク定義)をもとに設定・ルート・ビューをキャッシュする
#   2. RUN_MIGRATIONS=true の場合はマイグレーションを実行する
#   3. PHP-FPMとNginxを起動し、どちらかが終了したらコンテナごと終了する(ECSが新しいタスクに入れ替える)
set -euo pipefail

cd /var/www/html

# artisanはPHP-FPMのワーカーと同じwww-dataユーザーで実行する(rootで作ったキャッシュファイルをワーカーが更新できなくなるため)
artisan() {
    runuser -u www-data -- php artisan "$@"
}

# 設定のキャッシュは環境変数を読み込んで作るため、イメージのビルド時ではなく起動時に行う
artisan optimize

# タスクが1つの間は起動時に実行する。複数タスクを同時に起動する構成にする場合(CDのIssueで検討)は、
# 同時実行を避けるため、デプロイ時に1回だけ実行する方式(ECSのワンオフタスク等)に切り替える
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    artisan migrate --force
fi

php-fpm --nodaemonize &
fpm_pid=$!

nginx -g 'daemon off;' &
nginx_pid=$!

# ECSがタスクを停止する際のSIGTERMを両プロセスへ伝え、処理中のリクエストを終えてから終了させる
shutdown() {
    kill -QUIT "$nginx_pid" "$fpm_pid" 2>/dev/null || true
}
trap shutdown TERM INT QUIT

# どちらかのプロセスが終了するまで待つ
set +e
wait -n "$fpm_pid" "$nginx_pid"
status=$?
shutdown
wait
exit "$status"
