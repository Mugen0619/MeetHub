<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 本番はブラウザ → CloudFront(HTTPS)→ ALB(HTTP)の構成で、ALBはX-Forwarded-Protoに自身の受信プロトコル(http)を設定する。
        // そのままではアセット・リダイレクト・Livewireの通信先URLがhttp://で生成され、ブラウザにブロックされる(Mixed Content)ため、
        // APP_URLがhttps://の場合は常にhttps://でURLを生成する
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // ヘルスチェックの詳細(/health/details)で確認する項目。ALB・ECS用の /health はこれらを確認しない(LivenessController)
        Health::checks([
            DatabaseCheck::new(),
            // キャッシュはログイン試行回数の制限(RateLimiter)にも使うため、読み書きできることを確認する
            CacheCheck::new(),
        ]);
    }
}
