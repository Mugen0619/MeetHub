import http from 'k6/http';
import { BASE_URL } from './config.js';

// Livewire 3の画面操作(wire:click・wire:submit等)は、すべて共通の`POST /livewire/update`で送られる。
// ブラウザ上のLivewire(vendor/livewire/livewire/dist/livewire.esm.js の sendRequest / toRequestPayload)が
// 送信するのと同じ形式のリクエストを、画面のHTMLに埋め込まれた情報から組み立てる。
//
//   POST /livewire/update
//   Content-Type: application/json
//   X-Livewire: (空文字)
//   {
//     "_token": "<CSRFトークン(<meta name="csrf-token">)>",
//     "components": [{
//       "snapshot": "<wire:snapshot属性の値(JSON文字列のまま。チェックサムを含む)>",
//       "updates": { "form.email": "...", ... },    // wire:modelで入力された値
//       "calls": [{ "path": "", "method": "login", "params": [] }]
//     }]
//   }

function decodeHtmlAttribute(value) {
	return value
		.replace(/&quot;/g, '"')
		.replace(/&#0?39;/g, "'")
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&amp;/g, '&');
}

/**
 * 画面のHTMLから、指定した名前のLivewireコンポーネントと、リクエストに必要な情報(CSRFトークン・送信先)を取り出す。
 * 見つからない場合(未ログインでログイン画面にリダイレクトされた等)はnullを返す。
 *
 * @param {import('k6/http').RefinedResponse} page 画面をGETしたレスポンス
 * @param {string} name コンポーネント名(例: 'pages.auth.login')
 */
export function findComponent(page, name) {
	const body = String(page.body || '');
	const csrf = body.match(/<meta name="csrf-token" content="([^"]+)"/);
	const updateUri = body.match(/data-update-uri="([^"]+)"/);
	if (!csrf || !updateUri) {
		return null;
	}

	for (const match of body.matchAll(/wire:snapshot="([^"]+)"/g)) {
		const snapshot = decodeHtmlAttribute(match[1]);
		if (JSON.parse(snapshot).memo.name === name) {
			return {
				snapshot,
				csrfToken: csrf[1],
				// data-update-uriはAPP_URL基準の絶対URLになる場合があるため、パスだけを使う
				updatePath: decodeHtmlAttribute(updateUri[1]).replace(/^https?:\/\/[^/]+/, ''),
			};
		}
	}
	return null;
}

/**
 * Livewireコンポーネントのプロパティを更新したうえでメソッドを呼び出す(ブラウザでのボタン操作に相当)。
 *
 * @param {object} component findComponent()の戻り値
 * @param {object} updates 更新するプロパティ(例: { 'form.email': 'a@example.com' })
 * @param {string} method 呼び出すメソッド名
 * @param {string} tagName k6のメトリクスを操作ごとに分けて集計するためのタグ名
 * @param {object} [params] http.post()に渡す追加のパラメーター(jar等)
 */
export function callMethod(component, updates, method, tagName, params = {}) {
	const body = JSON.stringify({
		_token: component.csrfToken,
		components: [
			{
				snapshot: component.snapshot,
				updates,
				calls: [{ path: '', method, params: [] }],
			},
		],
	});

	return http.post(`${BASE_URL}${component.updatePath}`, body, {
		...params,
		headers: { 'Content-Type': 'application/json', 'X-Livewire': '' },
		tags: { name: tagName },
	});
}

/**
 * callMethod()のレスポンスから、更新後のコンポーネントの状態を取り出す。
 * 形式が想定と異なる場合(419・500等でHTMLが返った場合)はnullを返す。
 *
 * @returns {{ snapshot: object, effects: object } | null}
 */
export function parseResult(res) {
	if (res.status !== 200) {
		return null;
	}
	try {
		const component = res.json('components.0');
		return { snapshot: JSON.parse(component.snapshot), effects: component.effects || {} };
	} catch (e) {
		return null;
	}
}
