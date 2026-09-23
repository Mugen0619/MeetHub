import http from 'k6/http';
import { BASE_URL } from './config.js';
import { findComponent, callMethod, parseResult } from './livewire.js';

// setup()でテストデータのイベントを作成するためのヘルパー。

/**
 * 現在時刻から指定日数後の日時を、イベント作成画面の入力形式(datetime-local、Asia/Tokyo)で返す。
 */
function futureDateTimeLocal(daysLater) {
	const jst = new Date(Date.now() + daysLater * 24 * 60 * 60 * 1000 + 9 * 60 * 60 * 1000);
	return jst.toISOString().slice(0, 16); // 例: 2026-10-24T19:00
}

/**
 * イベント作成画面からイベントを作成する。作成後はダッシュボードへリダイレクトされ、IDは返らない。
 *
 * @param {object} jar 主催者としてログイン済みのCookieJar
 */
export function createEvent(jar, { title, capacity, daysLater = 30 }) {
	const page = http.get(`${BASE_URL}/events/create`, { jar, tags: { name: 'GET /events/create' } });
	const component = findComponent(page, 'pages.events.create');
	if (!component) {
		throw new Error(`event create page not available: ${page.status}`);
	}

	const res = callMethod(
		component,
		{
			'form.title': title,
			'form.starts_at': futureDateTimeLocal(daysLater),
			'form.location': 'k6負荷試験用の会場',
			'form.description': 'k6負荷試験用に自動作成したイベントです。',
			'form.capacity': capacity,
		},
		'save',
		'POST /livewire/update (create event)',
		{ jar },
	);
	const result = parseResult(res);
	if (!result || !result.effects.redirect) {
		throw new Error(`event creation failed: ${res.status} ${JSON.stringify(result && result.snapshot.memo.errors)}`);
	}
}

/**
 * ユーザーのプロフィール画面(主催する開催予定のイベント一覧)から、イベントIDを取得する。
 */
export function findOrganizedEventIds(jar, username) {
	const page = http.get(`${BASE_URL}/users/${username}`, { jar, tags: { name: 'GET /users/{username}' } });
	const ids = [...String(page.body).matchAll(/href="[^"]*\/events\/(\d+)"/g)].map((m) => Number(m[1]));
	return [...new Set(ids)];
}

/**
 * イベント詳細画面に表示されている現在の参加人数を返す。
 */
export function fetchParticipantsCount(jar, eventId) {
	const page = http.get(`${BASE_URL}/events/${eventId}`, { jar, tags: { name: 'GET /events/{event}' } });
	const match = String(page.body).match(/data-testid="participants-count">(\d+)</);
	return match ? Number(match[1]) : null;
}
