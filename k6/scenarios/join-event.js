import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Gauge } from 'k6/metrics';
import { BASE_URL } from '../lib/config.js';
import { registerUser, useSession } from '../lib/auth.js';
import { createEvent, findOrganizedEventIds, fetchParticipantsCount } from '../lib/events.js';
import { findComponent, callMethod, parseResult } from '../lib/livewire.js';
import { buildSummary } from '../lib/report.js';

// シナリオ: 参加申込みのバースト(定員管理の同時実行制御の検証)
//
// 定員CAPACITY人のイベントを1つ作成し、定員を上回るPARTICIPANTS人のVUが「ほぼ同時に」参加申込みボタンを押す。
// 目的はレスポンスタイムの計測ではなく、負荷がかかった状態でも定員を超過して登録されないこと
// (Event::join()の悲観ロック + 都度COUNTが正しく直列化されること)の検証。
//
// 1. setup(): 主催者・参加者を登録し、イベントを作成する(各参加者のログイン済みセッションCookieをVUへ渡す)
// 2. 各VU: イベント詳細画面を開いて申込みボタンのスナップショットを取得し、全VU共通の開始時刻まで待つ
// 3. 各VU: 開始時刻に一斉に「参加申込み」(POST /livewire/update で join を呼び出す)
// 4. teardown(): 主催者としてイベント詳細画面の参加人数を確認する
// 5. しきい値: 申込み成功件数 == 定員、定員到達による拒否件数 == 参加者数 - 定員、想定外の結果 == 0、最終的な参加人数 == 定員

const CAPACITY = Number(__ENV.CAPACITY || 20);
const PARTICIPANTS = Number(__ENV.PARTICIPANTS || 50);
// setup()終了から一斉申込みまでの待ち時間。全VUが詳細画面の取得を終えるのに十分な長さにする
const START_DELAY_MS = Number(__ENV.START_DELAY_MS || 15000);

const joinSucceeded = new Counter('join_succeeded');
const joinRejectedFull = new Counter('join_rejected_full');
const joinUnexpected = new Counter('join_unexpected');
const lateStart = new Counter('join_late_start');
const finalParticipants = new Gauge('event_participants_final');

export const options = {
	scenarios: {
		burst: {
			executor: 'per-vu-iterations',
			vus: PARTICIPANTS,
			iterations: 1,
			maxDuration: '2m',
		},
	},
	thresholds: {
		// 定員を超えて登録されていないこと(成功件数が定員とちょうど一致すること)の検証
		join_succeeded: [`count==${CAPACITY}`],
		join_rejected_full: [`count==${PARTICIPANTS - CAPACITY}`],
		join_unexpected: ['count==0'],
		// 全VUが開始時刻までに準備を終え、本当に一斉に申込んだこと(試験自体の妥当性)
		join_late_start: ['count==0'],
		event_participants_final: [`value==${CAPACITY}`],
		checks: ['rate==1'],
	},
	setupTimeout: '5m',
};

export function setup() {
	const organizer = registerUser('join_org');
	const organizerJar = useSession(organizer.sessionCookie, new http.CookieJar());
	createEvent(organizerJar, { title: `k6同時申込み検証(定員${CAPACITY})`, capacity: CAPACITY });
	const [eventId] = findOrganizedEventIds(organizerJar, organizer.username);
	if (!eventId) {
		throw new Error('created event not found on organizer profile');
	}

	const participants = [];
	for (let i = 0; i < PARTICIPANTS; i++) {
		participants.push(registerUser(`join_p${i}`).sessionCookie);
	}

	return {
		eventId,
		organizerSession: organizer.sessionCookie,
		participants,
		startAt: Date.now() + START_DELAY_MS,
	};
}

export default function (data) {
	useSession(data.participants[__VU - 1]);

	// 準備: イベント詳細画面を開き、参加申込みボタン(pages.events.show コンポーネント)の状態を取得する
	const page = http.get(`${BASE_URL}/events/${data.eventId}`, { tags: { name: 'GET /events/{event}' } });
	const component = findComponent(page, 'pages.events.show');
	if (!check(component, { 'event page has show component': (c) => c !== null })) {
		joinUnexpected.add(1);
		return;
	}

	// 全VU共通の開始時刻まで待ち、一斉に申込む
	const waitMs = data.startAt - Date.now();
	if (waitMs > 0) {
		sleep(waitMs / 1000);
	} else {
		lateStart.add(1);
	}

	const res = callMethod(component, {}, 'join', 'POST /livewire/update (join)');
	// 結果は再描画されたHTML(effects.html)で判定する。addError('participation', ...)のエラーはプロパティに
	// 紐づかないため、Livewireの仕様上スナップショット(memo.errors)には含まれない。
	// 拒否時はParticipationExceptionのメッセージ「定員に達しました。」(句点付き)がエラー表示に出る
	// (満員時に常に表示される「定員に達しました」(句点なし)とは区別できる)。
	const result = parseResult(res);
	const html = (result && result.effects.html) || '';

	if (html.includes('参加申込み済みです')) {
		joinSucceeded.add(1);
	} else if (html.includes('定員に達しました。')) {
		joinRejectedFull.add(1);
	} else {
		joinUnexpected.add(1);
		console.error(`unexpected join result (VU ${__VU}): status=${res.status}`);
		if (__ENV.DEBUG) {
			console.error(String(res.body).slice(0, 3000));
		}
	}
	check(res, { 'join request succeeded (200)': (r) => r.status === 200 });
}

export function teardown(data) {
	// 最終確認: 主催者としてイベント詳細画面を開き、表示される参加人数が定員と一致すること
	const jar = useSession(data.organizerSession, new http.CookieJar());
	const count = fetchParticipantsCount(jar, data.eventId);
	finalParticipants.add(count === null ? -1 : count);
	console.log(`event ${data.eventId}: participants=${count} / capacity=${CAPACITY}`);
	check(count, { 'participants count equals capacity (no overbooking)': (c) => c === CAPACITY });
}

export function handleSummary(data) {
	return buildSummary(data, 'report-join-event.html', `k6 Report: Join Event Burst (capacity ${CAPACITY}, ${PARTICIPANTS} VUs)`);
}
