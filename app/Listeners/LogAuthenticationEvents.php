<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;

/**
 * 認証まわりのイベントをログに記録し、ログイン中ユーザーのIDをログコンテキスト(userId)に登録する
 * (docs/observability.md)。Laravelのイベント自動検出により、handle*メソッドが各イベントのリスナーとして登録される。
 *
 * パスワードはもちろん、メールアドレス(個人情報)もログには出力しない。
 */
class LogAuthenticationEvents
{
    /**
     * セッションからユーザーを復元した時・ログインした時に発火する。以降のログに自動でuserIdが付く。
     */
    public function handleAuthenticated(Authenticated $event): void
    {
        Log::withContext(['userId' => $event->user->getAuthIdentifier()]);
    }

    public function handleLogin(Login $event): void
    {
        // LoginはAuthenticatedより先に発火し、まだコンテキストにuserIdがないため明示的に渡す
        Log::info('login succeeded', ['userId' => $event->user->getAuthIdentifier()]);
    }

    public function handleFailed(Failed $event): void
    {
        // 存在するアカウントへのパスワード誤りの場合のみ、対象アカウントのIDを記録する(総当たりの検知用)
        Log::warning('login failed', array_filter([
            'targetUserId' => $event->user?->getAuthIdentifier(),
            'ip' => request()->ip(),
        ]));
    }

    public function handleLockout(Lockout $event): void
    {
        Log::warning('login locked out', ['ip' => $event->request->ip()]);
    }

    public function handleLogout(Logout $event): void
    {
        Log::info('logout');

        // ログアウト後(同じリクエスト内のアクセスログ等)は未ログイン扱いとする
        Log::withoutContext(['userId']);
    }

    public function handleRegistered(Registered $event): void
    {
        // Registeredはログイン(Auth::login)より先に発火するため、userIdを明示的に渡す
        Log::info('user registered', ['userId' => $event->user->getAuthIdentifier()]);
    }
}
