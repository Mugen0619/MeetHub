# 技術スタック

[要件定義書](./requirements.md)へ戻る

技術スタックはRAISETIMELINE(2026年9月時点で選定)と同様、Java / Spring Boot backend + React frontend + PostgreSQLの構成を前提とするため、フレームワークの代替案比較は行わず、バージョン選定を中心に整理する。バージョンは2026年9月時点で確認できた各メジャーラインの最新安定版を記載する(実装着手時に再度最新パッチへの追従を確認すること)。

## フロントエンド

| 技術 | バージョン | 備考 |
|---|---|---|
| React | 19.2系 | |
| TypeScript | 5.9系 | |
| Vite | 7.3系 | Vite 8(Rolldownバンドラ搭載)は新メジャーとして存在するが、今回は7系を採用 |
| MUI(Material UI) | 7.3系 | |
| React Router | 7.18系 | 画面遷移・認証ガードに使用 |
| Vitest | 3.2系 | フロントエンドの単体・結合テスト |
| React Testing Library | 16.3系 | Vitestと組み合わせてコンポーネントテストに使用(`@testing-library/jest-dom` 6.9系、`@testing-library/user-event` 14.6系、`jsdom` 26.1系) |
| Node.js | 20系(LTS) | フロントエンドのビルド・テスト実行環境。GitHub Actions上も同バージョンで統一する |
| Playwright | 1.63系 | 代表的なユーザージャーニー・アクセシビリティ検査のE2Eテストに使用。バックエンド・フロントエンドを実際に起動した状態で実行する(ユーザージャーニー・アクセシビリティ検査はCIでも自動実行、ブラウザパフォーマンス計測はローカルのみ) |
| @axe-core/playwright | 4.13系 | Playwrightと組み合わせた主要画面のアクセシビリティ検査に使用 |
| k6 | 2.2系 | 代表的なAPI(イベント一覧取得・参加申込み・ログイン)の負荷試験に使用。特に参加申込みAPIは定員管理の同時実行制御を検証する目的で重点的に対象とする。個人開発規模を想定しローカル実行のみを対象とする |

## バックエンド

| 技術 | バージョン | 備考 |
|---|---|---|
| Java | 25(LTS) | 2025年9月GAのLTS版。次期LTSは27(2027年9月予定) |
| Spring Boot | 4.0系 | Spring Boot 4.0.xはJava 21〜25をサポート |
| Gradle | 9系 | Spring Boot 4.0.xの最小要件はGradle 8.14だが、9系を採用 |
| AWS SDK for Java v2 | 2.54系 | イベント画像アップロード用のS3署名付きURL(presigned URL)発行に使用(`S3Presigner`) |
| springdoc-openapi | 3.1系 | OpenAPI 3.1仕様書・Swagger UIの自動生成(`/swagger-ui.html`)。Spring Boot 4系に対応したv3系を採用 |
| logstash-logback-encoder | 9.0系 | ログのJSON構造化に使用。Jackson 3系に対応 |
| Spring Boot Actuator | Spring Boot 4.0系に準拠 | ALBヘルスチェック用に`/actuator/health`のみを公開(prodプロファイルで`health`以外は非公開) |
| Checkstyle | 14.1系 | 静的解析(Lint) |

## データベース

| 技術 | バージョン | 備考 |
|---|---|---|
| PostgreSQL | 17系 | PostgreSQL 18が新メジャーとして存在するが、今回は17系を採用 |

## コンテナ

| 技術 | バージョン | 備考 |
|---|---|---|
| Docker | ビルド確認は29.7系 | バックエンドをECS Fargateへデプロイするためのコンテナ化(`backend/Dockerfile`) |
| eclipse-temurin(ベースイメージ) | 25(JDK/JRE) | マルチステージビルド(ビルド:JDK、実行:JRE) |

## CI/CD

| 技術 | バージョン | 備考 |
|---|---|---|
| GitHub Actions | - | push・PR時にバックエンド/フロントエンドのテスト・Lint・E2Eテスト(ユーザージャーニー+アクセシビリティ検査)を自動実行するCI(`.github/workflows/ci.yml`)に加え、本番デプロイを自動化するCDワークフローも整備する(具体的なパイプライン設計は実装フェーズのIssueで決定) |
