# MeetHub データ設計たたき台

[要件定義書](./requirements.md)へ戻る

詳細設計に入る前のたたき台であり、詳細なER図・インデックス設計・カラムの型/制約の最終決定は実装フェーズで別途整理する。

## 主要エンティティ

| エンティティ | 主なカラム | 備考 |
|---|---|---|
| User | id, username, email, password_hash, display_name, bio, avatar_url, created_at | |
| Event | id, organizer_id(FK→User), title, description, location, starts_at, capacity, image_url, created_at, updated_at | organizer_idがイベント主催者。capacityは定員(1以上の整数を想定、null許容とするかは[TBD](./requirements.md#7-未決定事項tbd)) |
| EventLike | id, event_id(FK), user_id(FK), created_at | 「興味あり」。event_id + user_idでユニーク制約 |
| Comment | id, event_id(FK), user_id(FK), body, created_at | |
| Follow | id, follower_id(FK→User), followee_id(FK→User), created_at | follower_id + followee_idでユニーク制約 |
| EventParticipation | id, event_id(FK), user_id(FK), status(applied/cancelled), created_at, cancelled_at | 参加申込み(多対多の中間テーブル)。event_id + user_idでユニーク制約。現在の参加人数はstatus='applied'の行数をカウントする想定。取消し時に行を削除するかstatusを更新するかは実装フェーズで決定 |
| RefreshToken | id, user_id(FK→User), token_hash, expires_at, created_at | 生トークンは保存せずSHA-256ハッシュのみ保存。使用時に削除し新トークンを再発行(ローテーション) |

## 参加申込み・定員管理まわりの設計メモ

- `EventParticipation`はEventとUserの多対多を表す中間テーブル。主催者自身の行は作成しない(要件定義書[4.6](./requirements.md#46-参加申込み定員管理多対多リレーション)を参照)。
- 定員超過を防ぐための同時実行制御(行ロック・ユニーク制約+リトライ等の具体的な組み合わせ)は実装フェーズで設計する。
- 参加人数の集計方法(都度COUNT/非正規化カウンタ列を`Event`に持つ等)も実装フェーズで比較検討する。

## 関連ドキュメント

各画面とデータモデルの対応は[screen-design.md](./screen-design.md)を参照。
