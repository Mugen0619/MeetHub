<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * ALB・ECSのヘルスチェック用の軽量なエンドポイント(GET /health)。docs/observability.md 参照。
 *
 * Nginx → PHP-FPM → Laravelが起動してリクエストを処理できること(プロセスの生存)だけを確認し、
 * DB等の外部依存は確認しない。DB接続をここに含めると、RDSの障害時に全タスクが unhealthy と判定され、
 * ECSがタスクの入れ替えを繰り返してしまう(タスクを入れ替えてもDB障害は直らない)ため。
 * DB・キャッシュの確認は、詳細エンドポイント(/health/details、spatie/laravel-health)で行う。
 */
class LivenessController
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['healthy' => true])
            ->header('Cache-Control', 'no-store');
    }
}
