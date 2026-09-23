import { check, sleep } from 'k6';
import { RAMPING_STAGES, DEFAULT_THRESHOLDS } from '../lib/config.js';
import { registerUser, login } from '../lib/auth.js';
import { buildSummary } from '../lib/report.js';

// シナリオ: ログイン(GET /login → POST /livewire/update でLoginForm::authenticate()を呼び出す)
// 仮想ユーザー数を10→30→50人と段階的に増やし、同一アカウントへの並行ログインのレスポンスタイムを計測する。
// k6はイテレーションごとにCookieをリセットするため、毎回未ログインの状態からログインする。
export const options = {
	stages: RAMPING_STAGES,
	thresholds: {
		...DEFAULT_THRESHOLDS,
		// ログイン画面の表示と、ログイン処理(パスワードのハッシュ検証を含む)を分けて確認する
		'http_req_duration{name:GET /login}': ['p(95)<500'],
		'http_req_duration{name:POST /livewire/update (login)}': ['p(95)<500'],
	},
	setupTimeout: '2m',
};

export function setup() {
	const user = registerUser('login');
	return { email: user.email, password: user.password };
}

export default function (data) {
	const succeeded = login(data.email, data.password);
	check(succeeded, { 'login succeeded (redirect to dashboard)': (ok) => ok });
	sleep(1);
}

export function handleSummary(data) {
	return buildSummary(data, 'report-login.html', 'k6 Report: Login');
}
