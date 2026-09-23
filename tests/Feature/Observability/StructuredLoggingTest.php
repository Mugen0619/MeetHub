<?php

use App\Exceptions\ParticipationException;
use App\Http\Middleware\RequestLogging;
use App\Models\Event;
use App\Models\EventParticipation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->logPath = useJsonLogFile();
});

afterEach(function () {
    @unlink($this->logPath);
});

test('ログは1行1レコードのJSON形式で出力される', function () {
    Log::info('first message', ['foo' => 'bar']);
    Log::warning('second message');

    $lines = file($this->logPath, FILE_IGNORE_NEW_LINES);
    expect($lines)->toHaveCount(2);

    $record = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($record)
        ->message->toBe('first message')
        ->level_name->toBe('INFO')
        ->and($record['context']['foo'])->toBe('bar')
        ->and($record)->toHaveKey('datetime');

    expect(json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['level_name'])->toBe('WARNING');
});

test('リクエストごとに異なるtraceIdが発行され、アクセスログとレスポンスヘッダーに含まれる', function () {
    $first = $this->get('/');
    $second = $this->get('/');

    $accessLogs = findJsonLogs($this->logPath, 'http request completed');
    expect($accessLogs)->toHaveCount(2);

    $firstTraceId = $accessLogs[0]['context']['traceId'];
    $secondTraceId = $accessLogs[1]['context']['traceId'];

    expect($firstTraceId)->toBeUuid()
        ->and($secondTraceId)->toBeUuid()
        ->and($firstTraceId)->not->toBe($secondTraceId);

    $first->assertHeader(RequestLogging::TRACE_ID_HEADER, $firstTraceId);
    $second->assertHeader(RequestLogging::TRACE_ID_HEADER, $secondTraceId);

    expect($accessLogs[0]['level_name'])->toBe('INFO')
        ->and($accessLogs[0]['context'])->toMatchArray([
            'httpStatus' => 200,
            'method' => 'GET',
            'endpoint' => '/',
        ])
        ->and($accessLogs[0]['context']['durationMs'])->toBeInt();
});

test('未ログインのリクエストのログにはuserIdが含まれない', function () {
    $this->get('/');

    expect(findJsonLogs($this->logPath, 'http request completed')[0]['context'])
        ->not->toHaveKey('userId');
});

test('ログイン中のリクエストのログにはuserIdが含まれる', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(findJsonLogs($this->logPath, 'http request completed')[0]['context']['userId'])
        ->toBe($user->id);
});

test('アクセスログのendpointはルート定義のURIで記録され、URL中のトークンはログに残らない', function () {
    $this->get('/reset-password/secret-reset-token-123')->assertOk();

    expect(findJsonLogs($this->logPath, 'http request completed')[0]['context']['endpoint'])
        ->toBe('/reset-password/{token}')
        ->and(file_get_contents($this->logPath))->not->toContain('secret-reset-token-123');
});

test('ログイン成功がINFOレベルでuserId付きで記録される', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasNoErrors();

    $logs = findJsonLogs($this->logPath, 'login succeeded');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('INFO')
        ->and($logs[0]['context']['userId'])->toBe($user->id);
});

test('ログイン失敗がWARNINGレベルで記録され、メールアドレス・パスワードはログに残らない', function () {
    $user = User::factory()->create();

    Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password')
        ->call('login')
        ->assertHasErrors();

    $logs = findJsonLogs($this->logPath, 'login failed');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('WARNING')
        ->and($logs[0]['context']['targetUserId'])->toBe($user->id)
        ->and($logs[0]['context'])->toHaveKey('ip');

    expect(file_get_contents($this->logPath))
        ->not->toContain($user->email)
        ->not->toContain('wrong-password');
});

test('存在しないアカウントへのログイン失敗ではtargetUserIdを記録しない', function () {
    Volt::test('pages.auth.login')
        ->set('form.email', 'nobody@example.com')
        ->set('form.password', 'password')
        ->call('login')
        ->assertHasErrors();

    expect(findJsonLogs($this->logPath, 'login failed')[0]['context'])
        ->not->toHaveKey('targetUserId');
});

test('ログイン試行回数の上限に達するとWARNINGレベルで記録される', function () {
    $user = User::factory()->create();

    $component = Volt::test('pages.auth.login')
        ->set('form.email', $user->email)
        ->set('form.password', 'wrong-password');

    foreach (range(1, 6) as $_) {
        $component->call('login');
    }

    $logs = findJsonLogs($this->logPath, 'login locked out');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('WARNING');
});

test('ログアウトがINFOレベルで記録される', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('layout.navigation')->call('logout');

    $logs = findJsonLogs($this->logPath, 'logout');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('INFO')
        ->and($logs[0]['context']['userId'])->toBe($user->id);
});

test('ユーザー登録がINFOレベルでuserId付きで記録される', function () {
    Volt::test('pages.auth.register')
        ->set('username', 'new_user')
        ->set('display_name', 'New User')
        ->set('email', 'new@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('register')
        ->assertHasNoErrors();

    $logs = findJsonLogs($this->logPath, 'user registered');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['context']['userId'])->toBe(User::where('username', 'new_user')->value('id'));
});

test('参加申込みの成功がINFOレベルでイベントID付きで記録される', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['capacity' => 5]);

    Volt::test('pages.events.show', ['event' => $event])->call('join');

    $logs = findJsonLogs($this->logPath, 'participation created');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('INFO')
        ->and($logs[0]['context'])->toMatchArray(['eventId' => $event->id, 'userId' => $user->id]);
});

test('定員到達による参加申込みの拒否が、理由とともにWARNINGレベルで記録される', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $event = Event::factory()->create(['capacity' => 1]);
    EventParticipation::factory()->for($event)->create();

    Volt::test('pages.events.show', ['event' => $event])
        ->call('join')
        ->assertHasErrors('participation');

    $logs = findJsonLogs($this->logPath, 'participation rejected');
    expect($logs)->toHaveCount(1)
        ->and($logs[0]['level_name'])->toBe('WARNING')
        ->and($logs[0]['context'])->toMatchArray([
            'eventId' => $event->id,
            'reason' => 'full',
            'userId' => $user->id,
        ]);
});

test('開催日時を過ぎたイベントの参加取消しの拒否が、理由とともに記録される', function () {
    $user = User::factory()->create();
    $event = Event::factory()->past()->create();

    expect(fn () => $event->leave($user))->toThrow(ParticipationException::class);

    expect(findJsonLogs($this->logPath, 'participation cancel rejected')[0]['context'])
        ->toMatchArray(['eventId' => $event->id, 'reason' => 'cancel_after_start']);
});

test('参加取消しがINFOレベルで記録される', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();
    EventParticipation::factory()->for($event)->for($user)->create();

    $event->leave($user);

    expect(findJsonLogs($this->logPath, 'participation cancelled'))->toHaveCount(1);
});

test('権限エラー(403)がWARNINGレベルで記録され、同じリクエストのアクセスログと同じtraceId・userIdを持つ', function () {
    $user = User::factory()->create();
    $othersEvent = Event::factory()->create();

    $this->actingAs($user)->get(route('events.edit', $othersEvent))->assertForbidden();

    $denied = findJsonLogs($this->logPath, 'authorization denied');
    $access = findJsonLogs($this->logPath, 'http request completed');

    expect($denied)->toHaveCount(1)
        ->and($denied[0]['level_name'])->toBe('WARNING')
        ->and($denied[0]['context']['userId'])->toBe($user->id)
        ->and($denied[0]['context']['traceId'])->toBe($access[0]['context']['traceId'])
        ->and($access[0]['context']['httpStatus'])->toBe(403);

    // 403は想定内のクライアントエラーのため、ERRORレベルの例外ログは出さない
    $errors = array_filter(readJsonLogs($this->logPath), fn (array $r) => $r['level_name'] === 'ERROR');
    expect($errors)->toBeEmpty();
});

test('予期しない例外はERRORレベルで、traceIdとスタックトレース付きで記録される', function () {
    Route::get('/_test/unexpected-error', fn () => throw new RuntimeException('something broke'));

    // HTMLのエラー画面の描画は重いため、JSONでレスポンスを受け取る
    $this->getJson('/_test/unexpected-error')->assertInternalServerError();

    $errors = array_values(array_filter(readJsonLogs($this->logPath), fn (array $r) => $r['level_name'] === 'ERROR'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('something broke')
        ->and($errors[0]['context']['traceId'])->toBeUuid()
        ->and($errors[0]['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($errors[0]['context']['exception'])->toHaveKey('trace');
});
