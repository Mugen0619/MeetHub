<?php

use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Facades\Health;

beforeEach(function () {
    $this->logPath = useJsonLogFile();
});

afterEach(function () {
    @unlink($this->logPath);
});

test('ヘルスチェックは正常時に200を返し、詳細情報は含めない', function () {
    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['healthy' => true]);
});

test('正常なヘルスチェックはアクセスログに記録しない', function () {
    $this->get('/health')->assertOk();

    expect(findJsonLogs($this->logPath, 'http request completed'))->toBeEmpty();
});

test('チェックが失敗した場合は503を返し、アクセスログに記録する', function () {
    config(['database.connections.broken' => [
        'driver' => 'sqlite',
        'database' => '/nonexistent/meethub.sqlite',
    ]]);
    Health::clearChecks()->checks([DatabaseCheck::new()->connectionName('broken')]);

    $this->get('/health')
        ->assertStatus(503)
        ->assertDontSee('nonexistent');

    expect(findJsonLogs($this->logPath, 'http request completed')[0]['context'])
        ->toMatchArray(['httpStatus' => 503, 'endpoint' => '/health']);
});

test('ヘルスチェックはセッションを開始しない(ALBからのリクエストでセッションを作らない)', function () {
    $this->get('/health')
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});

test('詳細情報の公開が無効な場合、詳細エンドポイントは404を返す', function () {
    config(['health.expose_details' => false]);

    $this->get('/health/details')->assertNotFound();
});

test('詳細情報の公開が有効な場合、チェックごとの結果を返す', function () {
    config(['health.expose_details' => true]);

    $response = $this->get('/health/details')->assertOk();

    expect(collect($response->json('checkResults'))->pluck('name')->all())
        ->toBe(['Database', 'Cache']);
});
