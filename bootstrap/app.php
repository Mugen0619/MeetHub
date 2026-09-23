<?php

use App\Http\Middleware\RequestLogging;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::group([], __DIR__.'/../routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // traceIdを他のミドルウェアのログにも付けるため、グローバルミドルウェアの先頭で実行する
        $middleware->prepend(RequestLogging::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 認可エラー(403)はLaravel標準ではログに記録されないため、記録対象に戻したうえで、
        // 他人のリソースへの操作等のクライアント起因のエラーとしてWARNINGレベルで記録する(docs/observability.md)
        $exceptions->stopIgnoring(AuthorizationException::class);
        $exceptions->report(function (AuthorizationException $e) {
            Log::warning('authorization denied', [
                'exceptionType' => $e::class,
                'exceptionMessage' => $e->getMessage(),
            ]);

            // 標準のERRORレベルの例外ログ(スタックトレース付き)は出力しない
            return false;
        });
    })->create();
