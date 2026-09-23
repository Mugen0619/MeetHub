// 実行対象のアプリケーションURL。`k6 run -e BASE_URL=... <script>`で上書き可能。
export const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';

// セッションCookieの名前(config/session.phpの既定値: APP_NAMEをスラッグ化した「meethub-session」)。
export const SESSION_COOKIE = __ENV.SESSION_COOKIE || 'meethub-session';

// 段階的に仮想ユーザー数を増やすシナリオの共通ステージ定義(10→30→50人、合計5分間)。
// 個人開発規模の負荷試験を想定し、本番相当の大規模負荷(数千〜数万VU)は対象外とする。
export const RAMPING_STAGES = [
	{ duration: '30s', target: 10 },
	{ duration: '1m', target: 10 },
	{ duration: '30s', target: 30 },
	{ duration: '1m', target: 30 },
	{ duration: '30s', target: 50 },
	{ duration: '1m', target: 50 },
	{ duration: '30s', target: 0 },
];

// 95%のリクエストが500ms以内に完了すること、かつ失敗率が1%未満であることをしきい値とする。
export const DEFAULT_THRESHOLDS = {
	http_req_duration: ['p(95)<500'],
	http_req_failed: ['rate<0.01'],
};

// 負荷試験用のテストデータ(ユーザー名・メールアドレス)を実行ごとに一意にするための接頭辞。
export const RUN_ID = Date.now().toString(36);
