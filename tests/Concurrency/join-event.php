<?php

/**
 * 同時申込みテスト(ConcurrentJoinTest)から子プロセスとして起動し、1件の参加申込みを行うスクリプト。
 * 別プロセス(=別のDB接続・別トランザクション)から申込みを行うことで、実際の同時リクエストを再現する。
 *
 * 使い方: php tests/Concurrency/join-event.php <event_id> <user_id>
 * 結果を標準出力に "joined" または "rejected: <理由>" として出力する。
 */

use App\Exceptions\ParticipationException;
use App\Models\Event;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$event = Event::query()->findOrFail((int) $argv[1]);
$user = User::query()->findOrFail((int) $argv[2]);

try {
    $event->join($user);
    echo 'joined';
} catch (ParticipationException $e) {
    echo 'rejected: '.$e->getMessage();
}
