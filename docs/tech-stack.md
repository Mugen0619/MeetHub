# 技術スタック

[要件定義書](./requirements.md)へ戻る

RAISETIMELINE(Java / Spring Boot + React + PostgreSQL)で使用した言語(Java・TypeScript)は今回使用しないという方針のもと、バックエンド・フロントエンドともに **PHP + Laravel + Livewire** を採用する(採用理由・フレームワーク自体の比較検討は完了済みのため、本書では対象外とし、バージョン選定を中心に整理する)。データベースは言語制約の対象外のため、引き続きPostgreSQLを採用する。インフラ(AWS / ECS Fargate + RDS + S3 + CloudFront、Terraformによる構築)もRAISETIMELINEと同様の構成を踏襲するため変更しない。

Livewireはサーバーサイドでレンダリングした画面をAjaxで部分更新する仕組みのため、React/TypeScriptのような独立したSPAフロントエンドは持たない。そのため本書では「フロントエンド」「バックエンド」を分けず、Laravelアプリケーション1つとして技術要素を整理する。バージョンは2026年9月時点で確認できた各メジャーラインの最新安定版を記載する(実装着手時に再度最新パッチへの追従を確認すること)。

なお、認証方式(Laravel標準のセッション認証を採用)・E2Eテストツール(Laravel DuskではなくPestの組み込みブラウザテストを採用)は、本書作成時にレビューチャットで方針を確認済みの内容を反映している。

## アプリケーション本体

| 技術 | バージョン | 備考 |
|---|---|---|
| PHP | 8.5系 | Laravel 13.3以降はSymfony 8依存により実質PHP 8.4以上が必要になるため、最新安定版の8.5系を採用 |
| Laravel | 13.33系 | PHP 8.3〜8.5をサポート(13.3以降は実質8.4以上)。RAISETIMELINEのSpring Bootに相当するバックエンドフレームワーク |
| Livewire | 3.6系(`^3.6.4`) | Blade + PHPのみで動的なUIを実装するためのフレームワーク。JavaScriptをほぼ書かずに画面のインタラクティブ部分(参加申込みボタン・いいねトグル・コメント投稿等)を実装する。Livewire自体の最新安定版は4.4系(2026年9月時点)だが、Laravel Breeze 2.4.2のLivewireスタックインストーラーが`livewire/livewire:^3.6.4`を明示的に固定しているため、実際にインストールされるのは3.6系となる(本書の当初案は最新安定版の調査結果をそのまま転記した誤りで、実装時に判明したため訂正した)。Breezeが4系に対応次第、追従を検討する |
| Livewire Volt | 1.7系(`^1.7.0`) | Livewireコンポーネントを単一のBladeファイル内に関数型記法で書けるようにする公式パッケージ。Breeze 2.4.2のLivewireスタックが標準採用しており、認証画面(ログイン・登録等)もVolt記法で生成される |
| Alpine.js | Livewireに同梱のバージョンに準拠(3.15系) | Livewireが内部で利用する軽量JSライブラリ。個別にバージョン管理・追加設定は行わない |
| Laravel Breeze(Livewireスタック) | 2.4系(`^2.4`) | ログイン・ユーザー登録・プロフィール編集画面の認証スキャフォールディングに使用 |

## 認証

| 技術 | バージョン | 備考 |
|---|---|---|
| Laravel標準セッション認証(`web`ガード) | Laravel 13系に同梱 | 要件定義書[4.1](./requirements.md#41-アカウント)のログイン・登録・ログアウトを実現する認証方式。ログイン・登録・パスワード関連のバックエンドロジックは、Laravel Breeze(Livewireスタック)がスキャフォールディングするコントローラ内で`Auth`ファサード(`Auth::attempt()`等)を直接呼び出す形で実装する(Fortifyのような認証ロジックを隠蔽するパッケージは追加しない)。セッションはCookie + CSRF保護によって保護する |

将来、外部API・モバイルクライアント等の別クライアントが必要になった場合は、Laravelのマルチガード機構を用いて`api`ガード + JWT(例: `php-open-source-saver/jwt-auth`)を追加する想定とする(今回のスコープでは未実装。YAGNIの観点から、実際に必要になった時点で設計・導入する)。

## アセットビルド(CSS / JS)

| 技術 | バージョン | 備考 |
|---|---|---|
| Node.js | 22系(LTS) | Tailwind CSS・Livewire/Alpine.js用アセットをビルドするための実行環境。実行時(本番稼働時)にはNode.jsは不要で、ビルド時のみ使用する |
| Vite | 8.3系 | Laravel公式のVite統合(`laravel-vite-plugin`)経由でCSS/JSアセットをビルド |
| Tailwind CSS | 3.4系(`^3.1.0`) | ユーティリティファーストCSS。Bladeテンプレート・Livewireコンポーネントのスタイリングに使用。classic PostCSSプラグイン方式(`tailwind.config.js` + `postcss.config.js`)でビルドする。Tailwind CSS自体の最新安定版は4.3系(2026年9月時点)だが、Laravel Breeze 2.4.2のLivewireスタックスタブが`tailwindcss:^3.1.0`と3系向けのclassic設定ファイルを生成するため、実際に使われるのは3.4系となる(本書の当初案はLivewireと同様に最新安定版の調査結果をそのまま転記した誤りで、実装時に判明したため訂正した)。なお生成直後のスタブには未使用の`@tailwindcss/vite`(v4系プラグイン)が含まれていたが、`vite.config.js`から一切参照されておらず不要だったため削除した |

## ストレージ / AWS連携

| 技術 | バージョン | 備考 |
|---|---|---|
| aws/aws-sdk-php | 3.3xx系 | イベント画像アップロード用のS3署名付きURL(presigned URL)発行に使用 |
| league/flysystem-aws-s3-v3 | 3.35系 | LaravelのFilesystem抽象化からS3を操作するためのアダプタ(`config/filesystems.php`のs3ディスク) |

## ログ・死活監視

| 技術 | バージョン | 備考 |
|---|---|---|
| Monolog | Laravel 13系に同梱のバージョンに準拠 | Laravel標準のログ機構。JSON構造化フォーマッタでログ出力し、traceId/userIdをログコンテキストとして付与する |
| spatie/laravel-health | 1.40系 | ALBヘルスチェック用のエンドポイントを提供。prodプロファイルでは詳細情報を非公開にし、死活監視に必要な最小限の応答のみ返す |

## テスト

| 技術 | バージョン | 備考 |
|---|---|---|
| Pest | 5.0系 | 単体テスト・統合テストに使用(PHPUnit 13ベース)。従来案のVitestに相当 |
| pestphp/pest-plugin-laravel | 5.0系 | LaravelアプリのテストをPest構文で書くための統合プラグイン |
| pestphp/pest-plugin-browser | 5.0系 | Playwrightドライバを内蔵したPestのブラウザテスト機能。代表的なユーザージャーニー・アクセシビリティ検査のE2Eテストに使用する。Laravel Duskは、ChromeDriverのバージョン管理が不要でCI実行も高速・安定するPestのブラウザテストを優先し不採用とした |
| larastan/larastan(PHPStan) | 3.12系 | 静的解析。Eloquentモデル・リレーション等のLaravel固有の型推論に対応(Checkstyleに相当するLint) |
| Laravel Pint | 1.27系以降 | コードスタイルの自動整形 |
| laravel/pao | 1.0系 | 「Agent-optimized output for PHP testing tools」。PHPUnit/Pest/PHPStan/Rector等のCLI出力を、AIコーディングエージェント(Claude Code等)が読み取りやすい形式に整形する公式パッケージ。`laravel/laravel`スケルトンの標準devDependencyとして最初から含まれており、明示的に選定したものではない。テスト結果・挙動そのものには影響しない |
| k6 | 継続(要件定義書[8節](./requirements.md#8-非機能要件)を参照) | 代表的なAPI(イベント一覧取得・参加申込み・ログイン)の負荷試験に使用。シナリオ記述にJavaScriptを用いるが、アプリ本体の実装言語(PHP)ではないため変更なく継続採用する。個人開発規模を想定しローカル実行のみを対象とする |

## データベース

| 技術 | バージョン | 備考 |
|---|---|---|
| PostgreSQL | 17系 | PostgreSQL 18が新メジャーとして存在するが、今回は17系を採用(言語制約の対象外のため変更なし) |

## コンテナ

| 技術 | バージョン | 備考 |
|---|---|---|
| Docker | ビルド確認は最新安定版 | バックエンド(Laravelアプリ)をECS Fargateへデプロイするためのコンテナ化(`Dockerfile`) |
| PHPベースイメージ | php:8.5-fpm(公式イメージ) | マルチステージビルドを想定(ビルドステージでComposer/Node.jsによる依存解決・アセットビルドを行い、実行イメージにはNode.jsを含めない)。PHP-FPMの前段にNginxを配置する構成とする(詳細は実装フェーズで設計) |

## CI/CD

| 技術 | バージョン | 備考 |
|---|---|---|
| Composer | 2.10系 | PHPの依存関係管理。GitHub Actions上でキャッシュ対象とする |
| GitHub Actions | - | push・PR時にPest(単体・統合・ブラウザE2E+アクセシビリティ検査)・Laravel Pint・Larastanを自動実行するCI(`.github/workflows/ci.yml`)に加え、本番デプロイを自動化するCDワークフローも整備する(具体的なパイプライン設計は実装フェーズのIssueで決定) |
