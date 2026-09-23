# MeetHub

勉強会・イベント掲示板アプリ。詳細な要件・設計は[docs/](./docs/)を参照。

- [要件定義書](./docs/requirements.md)
- [技術スタック](./docs/tech-stack.md)
- [画面設計](./docs/screen-design.md)
- [データ設計](./docs/database-design.md)

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

### コード品質チェック

```bash
# コードスタイル(Laravel Pint)
docker compose run --rm app ./vendor/bin/pint

# 静的解析(Larastan)
docker compose run --rm app ./vendor/bin/phpstan analyse
```

## CI

GitHub Actions(`.github/workflows/ci.yml`)で、push・PR時にLaravel Pint・Larastan・Pestのテストを自動実行する。
