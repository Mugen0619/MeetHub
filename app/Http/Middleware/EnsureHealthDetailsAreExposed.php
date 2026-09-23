<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ヘルスチェックの詳細結果(/health/details)は、config('health.expose_details')が有効な環境でのみ公開する。
 * 本番相当の環境では存在しないURLと同じく404を返し、内部情報(DB接続エラーの内容等)を外部に出さない。
 */
class EnsureHealthDetailsAreExposed
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('health.expose_details'), 404);

        return $next($request);
    }
}
