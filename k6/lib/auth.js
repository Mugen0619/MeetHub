import http from 'k6/http';
import { BASE_URL, RUN_ID, SESSION_COOKIE } from './config.js';
import { findComponent, callMethod, parseResult } from './livewire.js';

// MeetHubの認証はLaravel標準のセッション認証(Cookie + CSRFトークン)で、ログイン・登録画面はLivewire(Volt)で実装されている。
// そのため「画面をGETしてCSRFトークンとコンポーネントのスナップショットを取得 → 同じCookieのまま /livewire/update へPOST」
// の2段階で操作する。k6は同じVU内のリクエストでCookieを自動的に保持する(別のCookieJarを渡した場合はそちらに保持する)。

export const DEFAULT_PASSWORD = 'LoadTest123!';

/**
 * ログインする。成功すると、使用したCookieJar(省略時はVUの既定のJar)にログイン済みのセッションCookieが入る。
 *
 * @param {string} email
 * @param {string} password
 * @param {object} [params] http呼び出しに渡す追加パラメーター(jar等)
 * @returns {boolean} ログインに成功したか(Livewireがダッシュボードへのリダイレクトを返したか)
 */
export function login(email, password, params = {}) {
	const page = http.get(`${BASE_URL}/login`, { ...params, tags: { name: 'GET /login' } });
	const component = findComponent(page, 'pages.auth.login');
	if (!component) {
		return false;
	}

	const res = callMethod(
		component,
		{ 'form.email': email, 'form.password': password },
		'login',
		'POST /livewire/update (login)',
		params,
	);
	const result = parseResult(res);
	return !!(result && result.effects.redirect && result.effects.redirect.endsWith('/dashboard'));
}

/**
 * 負荷試験専用のユーザーを画面から登録する(登録するとそのままログイン状態になる)。
 * 各ユーザーのセッションを混ぜないよう、ユーザーごとに新しいCookieJarを使う。
 *
 * @param {string} suffix ユーザー名の識別子(英数字・アンダースコア)
 * @returns {{ username: string, email: string, password: string, sessionCookie: string }}
 */
export function registerUser(suffix) {
	const jar = new http.CookieJar();
	const username = `k6_${RUN_ID}_${suffix}`;
	const email = `${username}@example.com`;

	const page = http.get(`${BASE_URL}/register`, { jar, tags: { name: 'GET /register' } });
	const component = findComponent(page, 'pages.auth.register');
	if (!component) {
		throw new Error(`register page not available: ${page.status}`);
	}

	const res = callMethod(
		component,
		{
			username,
			display_name: username,
			email,
			password: DEFAULT_PASSWORD,
			password_confirmation: DEFAULT_PASSWORD,
		},
		'register',
		'POST /livewire/update (register)',
		{ jar },
	);
	const result = parseResult(res);
	if (!result || !result.effects.redirect) {
		throw new Error(`user registration failed for ${username}: ${res.status} ${JSON.stringify(result && result.snapshot.memo.errors)}`);
	}

	return { username, email, password: DEFAULT_PASSWORD, sessionCookie: sessionCookieOf(jar) };
}

/**
 * CookieJarに入っているセッションCookieの値を返す(setup()で作ったログイン状態を各VUへ引き渡すため)。
 */
export function sessionCookieOf(jar) {
	const cookies = jar.cookiesForURL(BASE_URL)[SESSION_COOKIE];
	if (!cookies || cookies.length === 0) {
		throw new Error(`session cookie "${SESSION_COOKIE}" not found`);
	}
	return cookies[0];
}

/**
 * setup()で取得したセッションCookieをCookieJar(省略時はこのVUの既定のJar)に設定する。
 * 以降、そのJarを使うリクエストはそのユーザーとしてログイン済みの状態で扱われる。
 */
export function useSession(sessionCookie, jar = http.cookieJar()) {
	jar.set(BASE_URL, SESSION_COOKIE, sessionCookie);
	return jar;
}
