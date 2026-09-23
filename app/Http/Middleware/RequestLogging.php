<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * リクエスト単位のtraceIdを発行してログコンテキストに登録し、リクエスト完了時にアクセスログを1行出力する
 * (docs/observability.md)。他のミドルウェアのログにもtraceIdが付くよう、グローバルミドルウェアの先頭に登録する。
 */
class RequestLogging
{
    public const TRACE_ID_HEADER = 'X-Trace-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $traceId = (string) Str::uuid();

        // 同じプロセスで処理した前のリクエストのコンテキスト(userId等)が混ざらないよう、クリアしてから登録する
        Log::withoutContext();
        Log::withContext(['traceId' => $traceId]);

        // userIdは通常、セッションからユーザーを復元した時点でLogAuthenticationEventsが登録する。
        // ガードが既にユーザーを保持している場合(テストのactingAs等)は復元イベントが発火しないため、ここで登録する
        if (Auth::hasUser()) {
            Log::withContext(['userId' => Auth::id()]);
        }

        $response = $next($request);

        // 問い合わせ時にブラウザのNetworkタブ等から該当リクエストのログを辿れるよう、レスポンスヘッダーでも返す
        $response->headers->set(self::TRACE_ID_HEADER, $traceId);

        // ALBから定期的に届く正常なヘルスチェックはログを埋め尽くすため記録しない(失敗時は記録する)
        if (! ($request->is('health') && $response->isSuccessful())) {
            Log::info('http request completed', [
                'httpStatus' => $response->getStatusCode(),
                'method' => $request->method(),
                'endpoint' => $this->endpoint($request),
                'durationMs' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ]);
        }

        return $response;
    }

    /**
     * 実際のパスではなくルート定義のURI(例: /reset-password/{token})を返す。
     * パスに含まれるトークン等の秘密情報がログに残らないようにするため。
     */
    private function endpoint(Request $request): string
    {
        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : $request->path();

        return '/'.ltrim($uri, '/');
    }
}
