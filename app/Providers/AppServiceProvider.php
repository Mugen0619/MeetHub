<?php

namespace App\Providers;

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
        // ヘルスチェック(/health)で確認する項目。いずれかが失敗するとALBへ503を返す
        Health::checks([
            DatabaseCheck::new(),
            // キャッシュはログイン試行回数の制限(RateLimiter)にも使うため、読み書きできることを確認する
            CacheCheck::new(),
        ]);
    }
}
