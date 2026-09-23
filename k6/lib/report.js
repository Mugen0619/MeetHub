import { htmlReport } from 'https://raw.githubusercontent.com/benc-uk/k6-reporter/3.0.4/dist/bundle.js';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.1.0/index.js';

// 各シナリオのhandleSummary()から呼び出す共通ヘルパー(RAISETIMELINEのk6/lib/report.jsと同じ仕組み)。
// ターミナルへのテキストサマリー出力と、report/配下へのHTMLレポート出力を両方行う。
// HTMLレポートは実行のたびに生成される成果物のため、Git管理対象外(.gitignore)にしている。
export function buildSummary(data, htmlFileName, title) {
	return {
		stdout: textSummary(data, { indent: ' ', enableColors: true }),
		[`report/${htmlFileName}`]: htmlReport(data, { title }),
	};
}
