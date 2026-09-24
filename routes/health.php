<?php

use App\Http\Controllers\LivenessController;
use App\Http\Middleware\EnsureHealthDetailsAreExposed;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;

/*
| ヘルスチェック(docs/observability.md)。ALBから定期的に呼ばれるため、セッション(DBへの書き込み)を伴う
| webミドルウェアグループには含めない。
*/

// ALB・ECSのヘルスチェック用。アプリが起動していれば常に200 {"healthy":true} を返し、DB等の外部依存は確認しない
Route::get('health', LivenessController::class)->name('health');

// DB接続・キャッシュを含むチェックごとの詳細結果(spatie/laravel-health。チェック内容はAppServiceProviderで登録)。
// 内部情報を含むため、本番相当の設定(HEALTH_EXPOSE_DETAILS未設定)では404になる
Route::get('health/details', HealthCheckJsonResultsController::class)
    ->middleware(EnsureHealthDetailsAreExposed::class)
    ->name('health.details');
