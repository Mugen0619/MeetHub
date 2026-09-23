<?php

use App\Http\Middleware\EnsureHealthDetailsAreExposed;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Http\Controllers\HealthCheckJsonResultsController;
use Spatie\Health\Http\Controllers\SimpleHealthCheckController;

/*
| ヘルスチェック(docs/observability.md)。ALBから定期的に呼ばれるため、セッション(DBへの書き込み)を伴う
| webミドルウェアグループには含めない。チェック内容はAppServiceProviderで登録する。
*/

// ALBのヘルスチェック用。全チェック成功なら200 {"healthy":true}、失敗なら503を返し、詳細は含めない
Route::get('health', SimpleHealthCheckController::class)->name('health');

// チェックごとの詳細結果(ローカル開発用)。本番相当の設定では404になる
Route::get('health/details', HealthCheckJsonResultsController::class)
    ->middleware(EnsureHealthDetailsAreExposed::class)
    ->name('health.details');
