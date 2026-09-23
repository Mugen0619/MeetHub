<?php

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

/**
 * FILESYSTEM_DISK=s3 のとき、画像がブラウザからS3へ署名付きURL(presigned URL)で直接アップロードされる構成になっていることを確認する。
 * 署名付きURLの生成はAWS SDKがローカルで計算するため、ダミーの認証情報でAWSに接続せずに検証できる。
 */
test('s3ディスク使用時はS3への署名付きアップロードURLが発行される', function () {
    $s3 = [
        'driver' => 's3',
        'key' => 'dummy-key',
        'secret' => 'dummy-secret',
        'region' => 'ap-northeast-1',
        'bucket' => 'meethub-test-bucket',
    ];

    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3' => $s3,
        'livewire.temporary_file_upload.disk' => 's3',
        // テスト実行中のLivewireは一時ファイルを 'tmp-for-tests' ディスクに保存するため、そのディスクもS3設定にする
        'filesystems.disks.tmp-for-tests' => $s3,
    ]);

    expect(FileUploadConfiguration::isUsingS3())->toBeTrue();

    $signed = (new GenerateSignedUploadUrl)->forS3(UploadedFile::fake()->image('cover.jpg'));

    expect($signed['url'])
        ->toStartWith('https://meethub-test-bucket.s3.ap-northeast-1.amazonaws.com/livewire-tmp/')
        ->toContain('X-Amz-Signature=')
        ->toContain('X-Amz-Expires=');
});
