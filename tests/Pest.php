<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// 同時実行制御のテストは、別プロセス(別のDB接続)からテストデータが見えるよう、
// トランザクションで囲むRefreshDatabaseではなく、コミットしてテスト後にテーブルを空にするDatabaseTruncationを使う
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * 以降のログを、本番と同じJSONチャンネルで一時ファイルに出力するよう切り替え、そのファイルのパスを返す。
 * ログチャンネルを作り直すため、ログコンテキストを使う操作(actingAs等)より前に呼ぶこと。
 */
function useJsonLogFile(): string
{
    $path = storage_path('logs/testing-'.Str::uuid().'.log');

    config(['logging.default' => 'json', 'logging.channels.json.path' => $path]);
    Log::forgetChannel('json');

    return $path;
}

/**
 * useJsonLogFile()で出力したログを1行ずつJSONとしてデコードして返す。
 *
 * @return list<array<string, mixed>>
 */
function readJsonLogs(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
}

/**
 * 指定したメッセージのログレコードを全て返す。
 *
 * @return list<array<string, mixed>>
 */
function findJsonLogs(string $path, string $message): array
{
    return array_values(array_filter(readJsonLogs($path), fn (array $record) => $record['message'] === $message));
}
