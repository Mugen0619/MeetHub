<?php

use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * 参加申込みの同時実行制御(悲観ロック)を、実際のPostgreSQL上で検証する。
 * SQLiteとPostgreSQLでは行ロックの挙動が異なるため、このテストはphpunit.pgsql.xml(PostgreSQL)でのみ実行する。
 */

/**
 * 別プロセスで参加申込みを実行する。
 *
 * @return array{0: resource, 1: array<int, resource>}
 */
function spawnJoinProcess(Event $event, User $user): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/join-event.php', (string) $event->id, (string) $user->id],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        // テスト用の接続先(phpunit.pgsql.xmlで指定したDB等)を子プロセスにも引き継ぐ
        getenv(),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('申込み用の子プロセスを起動できませんでした。');
    }

    return [$process, $pipes];
}

/**
 * 子プロセスの終了を待ち、出力(申込み結果)を返す。
 *
 * @param  array{0: resource, 1: array<int, resource>}  $spawned
 */
function finishJoinProcess(array $spawned): string
{
    [$process, $pipes] = $spawned;
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("申込み用の子プロセスが異常終了しました(exit {$exitCode}): {$stdout}{$stderr}");
    }

    return trim((string) $stdout);
}

/**
 * このDBで、行ロックの解放を待っている他の接続の数。
 */
function lockWaitingConnections(): int
{
    // pg_stat_activityはトランザクション内で最初に参照した時点のスナップショットを返し続けるため、毎回破棄する
    DB::statement('select pg_stat_clear_snapshot()');

    return (int) DB::scalar(
        "select count(*) from pg_stat_activity
         where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()"
    );
}

/**
 * 複数ユーザーの参加申込みを、別プロセスから同時に実行し、各申込みの結果を(並び順を揃えて)返す。
 *
 * 申込みを確実に同時に走らせるため、先にこのプロセスでイベント行をロックしておき、
 * すべての申込みがロック待ちになったことを確認してから、ロックを解放して一斉に進ませる。
 *
 * @return list<string>
 */
function joinConcurrently(Event $event, User ...$users): array
{
    DB::beginTransaction();
    Event::query()->lockForUpdate()->findOrFail($event->id);

    $processes = array_map(fn (User $user) => spawnJoinProcess($event, $user), $users);

    $deadline = microtime(true) + 30;
    while (lockWaitingConnections() < count($users) && microtime(true) < $deadline) {
        usleep(50_000);
    }
    $waiting = lockWaitingConnections();

    DB::rollBack();
    $results = array_map(finishJoinProcess(...), $processes);

    expect($waiting)->toBe(count($users), "申込みが同時にロック待ちの状態にならなかった(ロック待ち: {$waiting}件)");

    sort($results);

    return $results;
}

test('定員残り1枠に2人が同時に申込んでも、1人だけが登録され、もう1人は定員到達で拒否される', function () {
    $event = Event::factory()->create(['capacity' => 1]);
    [$alice, $bob] = User::factory()->count(2)->create()->all();

    expect(joinConcurrently($event, $alice, $bob))->toBe(['joined', 'rejected: 定員に達しました。'])
        ->and($event->participations()->count())->toBe(1);
});

test('定員残り2枠に3人が同時に申込んでも、定員を超えて登録されない', function () {
    $event = Event::factory()->create(['capacity' => 3]);
    EventParticipation::factory()->for($event)->create();
    $users = User::factory()->count(3)->create()->all();

    expect(joinConcurrently($event, ...$users))->toBe(['joined', 'joined', 'rejected: 定員に達しました。'])
        ->and($event->participations()->count())->toBe(3);
});

test('同じユーザーが同時に2回申込んでも、登録は1件だけになる', function () {
    $event = Event::factory()->create(['capacity' => 5]);
    $user = User::factory()->create();

    expect(joinConcurrently($event, $user, $user))->toBe(['joined', 'rejected: このイベントには既に申込み済みです。'])
        ->and($event->participations()->count())->toBe(1);
});
