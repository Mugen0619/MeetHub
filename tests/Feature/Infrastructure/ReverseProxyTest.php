<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Health\Facades\Health;

/*
| 本番の ブラウザ → CloudFront → ALB → ECS 構成で、クライアントIP・URLのスキームを正しく扱えること(docs/infrastructure.md)。
*/

// ALB(VPC内)とCloudFront(オリジン向けIPレンジの一例)を信頼する、本番相当の設定
const TRUSTED_PROXIES = '10.2.0.0/16,130.176.0.0/18';
const ALB_IP = '10.2.1.25';
const CLOUDFRONT_IP = '130.176.10.20';
const VIEWER_IP = '203.0.113.9';

beforeEach(function () {
    Route::get('/_test/client-ip', fn (Request $request) => response()->json(['ip' => $request->ip()]));
});

test('CloudFront・ALB経由のリクエストでは、閲覧者のIPをクライアントIPとして扱う', function () {
    config(['trustedproxy.proxies' => TRUSTED_PROXIES]);

    $this->withServerVariables(['REMOTE_ADDR' => ALB_IP])
        ->withHeader('X-Forwarded-For', VIEWER_IP.', '.CLOUDFRONT_IP)
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => VIEWER_IP]);
});

test('クライアントが偽のX-Forwarded-Forを送っても、IPを詐称できない', function () {
    config(['trustedproxy.proxies' => TRUSTED_PROXIES]);

    // CloudFrontはクライアントが送ってきたX-Forwarded-Forの末尾に、実際の閲覧者のIPを追記する
    $this->withServerVariables(['REMOTE_ADDR' => ALB_IP])
        ->withHeader('X-Forwarded-For', '198.51.100.77, '.VIEWER_IP.', '.CLOUDFRONT_IP)
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => VIEWER_IP]);
});

test('信頼するプロキシが未設定(ローカル開発)の場合は、X-Forwarded-Forを信頼しない', function () {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.1'])
        ->withHeader('X-Forwarded-For', VIEWER_IP)
        ->getJson('/_test/client-ip')
        ->assertJson(['ip' => '172.20.0.1']);
});

test('APP_URLがhttps://の場合、ALBからHTTPで届いたリクエストでもURLはhttps://で生成する', function () {
    config(['app.url' => 'https://example.cloudfront.net']);
    // AppServiceProvider::boot()を設定変更後に再実行する(ヘルスチェックの二重登録を避けるため、先に登録を消す)
    Health::clearChecks();
    (new AppServiceProvider(app()))->boot();

    expect(route('login'))->toStartWith('https://');
});
