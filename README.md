# MeetHub

勉強会・イベント掲示板アプリ。詳細な要件・設計は[docs/](./docs/)を参照。

- [要件定義書](./docs/requirements.md)
- [技術スタック](./docs/tech-stack.md)
- [画面設計](./docs/screen-design.md)
- [データ設計](./docs/database-design.md)
- [構造化ログ・ヘルスチェック](./docs/observability.md)
- [運用ガイド(ログ調査・簡易インシデント対応)](./docs/operations-guide.md)
- [k6負荷試験](./k6/README.md)

## 技術スタック(概要)

PHP 8.5 + Laravel 13 + Livewire 3.6(Volt) + Blade + Tailwind CSS + PostgreSQL 17。詳細は[tech-stack.md](./docs/tech-stack.md)を参照。

## ローカル開発環境

ローカルにPHP 8.5 / Composerが入っていなくても、Docker ComposeだけでPHP・Node.js・PostgreSQLが揃った開発環境が起動できる。

### 前提

- Docker / Docker Compose

### セットアップ

```bash
# 1. 環境変数ファイルを作成
cp .env.example .env

# 2. アプリ用コンテナのビルド
docker compose build

# 3. PostgreSQLコンテナを起動
docker compose up -d pgsql

# 4. PHP依存関係をインストール(初回のみ。以降は composer.lock 変更時に実行)
docker compose run --rm app composer install

# 5. アプリケーションキーを生成
docker compose run --rm app php artisan key:generate

# 6. フロントエンドアセット(Tailwind CSS等)のビルド
docker compose run --rm app npm install
docker compose run --rm app npm run build

# 7. マイグレーションを実行
docker compose run --rm app php artisan migrate

# 8. アップロード画像を公開するためのシンボリックリンク(public/storage)を作成
docker compose run --rm app php artisan storage:link
```

※ ホストの5432番ポートが他プロジェクトのPostgreSQLで使用中の場合は、先にそちらを停止するか、`docker-compose.yml`のポートマッピングを変更すること(CLAUDE.mdのポート運用ルール参照)。

### アプリケーションの起動

```bash
docker compose up -d app
```

`http://localhost:8000` にアクセスし、新規登録・ログイン・ログアウトが動作することを確認する。

停止する場合:

```bash
docker compose down
```

### ファイル保存先(画像アップロード)

`.env`の`FILESYSTEM_DISK`で切り替える。ローカル開発は`public`(`storage/app/public`に保存、AWS認証情報は不要)、本番は`s3`(ブラウザからS3へ署名付きURLで直接アップロード。`AWS_*`の設定が必要)。詳細は[tech-stack.md](./docs/tech-stack.md#ストレージ--aws連携)を参照。

なお、アプリのタイムゾーンは`Asia/Tokyo`(`config/app.php`)で、イベントの開催日時は日本時間として入力・表示・「終了」判定する。

### テストの実行(Pest)

```bash
docker compose run --rm app php artisan test
```

通常のテスト(`phpunit.xml`)はSQLiteのインメモリDBで実行する。参加申込みの同時実行制御(行ロック)はDB製品によって挙動が異なるため、`tests/Concurrency`のテストは本番と同じPostgreSQL上で、`phpunit.pgsql.xml`を使って実行する(全テストもあわせてPostgreSQLで実行される)。`php artisan test`は`phpunit.xml`を自動で指定するため`--configuration`と併用できない(二重指定の警告で終了コードが1になる)ので、Pestを直接実行する。

```bash
# 初回のみ: テスト用データベースを作成
docker compose exec pgsql psql -U meethub -d meethub -c "create database meethub_testing"

docker compose run --rm app ./vendor/bin/pest --configuration=phpunit.pgsql.xml
```

### E2Eテストの実行(Pestのブラウザテスト)

代表的なユーザージャーニーと主要画面のアクセシビリティ検査を、Pestのブラウザテスト機能(`pestphp/pest-plugin-browser`、内部でPlaywrightのChromiumを使用)で実行する。テストは`tests/Browser`にあり、PostgreSQL上で実行するため`phpunit.pgsql.xml`の`Browser`テストスイートとして実行する。

| ファイル | 内容 |
|---|---|
| `tests/Browser/UserJourneyTest.php` | 主催者(新規登録→イベント作成→編集→削除)、参加者(新規登録→詳細→いいね→コメント→参加申込み→参加予定一覧→取消し)、フォロー(ログイン→フォロー→「フォロー中」タブ→フォロー解除)の3本 |
| `tests/Browser/AccessibilityTest.php` | ログイン・登録・イベント一覧・イベント詳細(参加者/主催者)・プロフィール画面をaxe-core(デフォルトルールセット、影響度minorまで全て)で検査する。違反が見つかった場合は、テストではなく画面(Blade・Livewireコンポーネント)を修正する |

```bash
# 画面のアセット(CSS/JS)がビルドされている必要がある(未ビルドの場合)
docker compose run --rm app npm run build

docker compose run --rm app ./vendor/bin/pest --configuration=phpunit.pgsql.xml --testsuite=Browser
```

- ブラウザ(Chromium)とその実行に必要なライブラリはDockerイメージに含めている(`Dockerfile`)。`package.json`のplaywrightのバージョンを上げた場合は、`Dockerfile`のバージョンも揃えてイメージを作り直す(`docker compose build app`)
- アプリは別途起動しておく必要はない(Pestがテストと同じプロセス内でHTTPサーバーを起動し、テストと同じDB接続を使う)
- 失敗した場合、失敗時点の画面のスクリーンショットが`tests/Browser/Screenshots/`に保存される(Git管理対象外)
- `phpunit.pgsql.xml`で`--testsuite`を指定せずに実行すると、E2Eテストも含めた全テストが実行される

### コード品質チェック

```bash
# コードスタイル(Laravel Pint)
docker compose run --rm app ./vendor/bin/pint

# 静的解析(Larastan)
docker compose run --rm app ./vendor/bin/phpstan analyse
```

## CI

GitHub Actions(`.github/workflows/ci.yml`)で、push・PR時にLaravel Pint・Larastan・Pestのテスト(SQLite・PostgreSQLの両方)と、E2Eテスト(ユーザージャーニー・アクセシビリティ検査。PostgreSQLのサービスコンテナを使用)を自動実行する。E2Eテストが失敗した場合は、スクリーンショットをアーティファクト(`e2e-screenshots`)として保存する。k6による負荷試験は、CIの実行環境の性能が変動するためCIでは実行しない([k6/README.md](./k6/README.md))。
