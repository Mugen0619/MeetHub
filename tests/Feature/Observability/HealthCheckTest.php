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

/**
 * DB接続のチェックが失敗する状態にする(RDSの障害を想定)。
 */
function breakDatabaseCheck(): void
{
    config(['database.connections.broken' => [
        'driver' => 'sqlite',
        'database' => '/nonexistent/meethub.sqlite',
    ]]);
    Health::clearChecks()->checks([DatabaseCheck::new()->connectionName('broken')]);
}

test('DBに接続できなくても、ALB・ECS用のヘルスチェックは200を返す(DB障害でタスクを入れ替え続けないため)', function () {
    breakDatabaseCheck();

    $this->get('/health')
        ->assertOk()
        ->assertExactJson(['healthy' => true]);
});

test('DBに接続できない場合、詳細エンドポイントでは失敗したチェックを確認できる', function () {
    config(['health.expose_details' => true]);
    breakDatabaseCheck();

    $response = $this->get('/health/details')->assertOk();

    expect($response->json('checkResults.0'))
        ->toMatchArray(['name' => 'Database', 'status' => 'failed']);
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
