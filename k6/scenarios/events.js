import http from 'k6/http';
import { check, sleep } from 'k6';
import { BASE_URL, RAMPING_STAGES, DEFAULT_THRESHOLDS } from '../lib/config.js';
import { registerUser, useSession } from '../lib/auth.js';
import { createEvent } from '../lib/events.js';
import { buildSummary } from '../lib/report.js';

const VIEWER_POOL_SIZE = 5;
// 一覧は1ページ12件のため、1ページ目が埋まる件数を用意する
const EVENT_COUNT = 15;

// シナリオ: イベント一覧取得(GET /events)
// 開催予定のイベント(主催者を含む)を開催日時が近い順に表示する一覧画面の、初回表示の負荷特性を確認する。
export const options = {
	stages: RAMPING_STAGES,
	thresholds: {
		...DEFAULT_THRESHOLDS,
		'http_req_duration{name:GET /events}': ['p(95)<500'],
	},
	setupTimeout: '3m',
};

export function setup() {
	// 主催者ユーザーで、一覧にある程度のデータ量を作っておく
	const organizer = registerUser('ev_org');
	const jar = useSession(organizer.sessionCookie, new http.CookieJar());
	for (let i = 0; i < EVENT_COUNT; i++) {
		createEvent(jar, { title: `k6負荷試験イベント #${i + 1}`, capacity: 30, daysLater: 30 + i });
	}

	// 複数の閲覧用ユーザーを用意し、各VUがプールから使い回す(全VUで1アカウントを使い回さない)
	const viewers = [];
	for (let i = 0; i < VIEWER_POOL_SIZE; i++) {
		viewers.push(registerUser(`ev_viewer${i}`).sessionCookie);
	}

	return { viewers };
}

export default function (data) {
	// k6はイテレーションごとにCookieをリセットするため、毎回ログイン済みのセッションCookieを設定する
	useSession(data.viewers[(__VU - 1) % data.viewers.length]);

	const res = http.get(`${BASE_URL}/events`, { tags: { name: 'GET /events' }, redirects: 0 });
	check(res, {
		'events fetch succeeded (200)': (r) => r.status === 200,
		// 未ログイン扱い(ログイン画面へのリダイレクト)になっていないこと、一覧にイベントが表示されていること
		'events are listed': (r) => /href="[^"]*\/events\/\d+"/.test(String(r.body)),
	});
	sleep(1);
}

export function handleSummary(data) {
	return buildSummary(data, 'report-events.html', 'k6 Report: Event List (GET /events)');
}
