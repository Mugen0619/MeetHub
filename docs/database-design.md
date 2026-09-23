# MeetHub データ設計たたき台

[要件定義書](./requirements.md)へ戻る

詳細設計に入る前のたたき台であり、詳細なER図・インデックス設計・カラムの型/制約の最終決定は実装フェーズで別途整理する。

## 主要エンティティ

| エンティティ | 主なカラム | 備考 |
|---|---|---|
| User | id, username, email, password_hash, display_name, bio, avatar_url, created_at | |
| Event | id, organizer_id(FK→User), title, description, location, starts_at, capacity, image_url, created_at, updated_at | organizer_idがイベント主催者。capacityは定員(1以上の整数を想定、null許容とするかは[TBD](./requirements.md#7-未決定事項tbd))。organizer_idは`ON DELETE CASCADE`とし、**主催者がアカウントを削除した場合、そのユーザーが主催する全イベント(および紐づくコメント・いいね・参加申込み)も連鎖削除される**(意図した仕様。他の参加者への通知は行わない。今回の課題規模では許容する判断とした) |
| EventLike | id, event_id(FK), user_id(FK), created_at | 「興味あり」。event_id + user_idでユニーク制約 |
| Comment | id, event_id(FK), user_id(FK), body, created_at | |
| Follow | id, follower_id(FK→User), followee_id(FK→User), created_at | follower_id + followee_idでユニーク制約 |
| EventParticipation | id, event_id(FK), user_id(FK), status(applied/cancelled), created_at, cancelled_at | 参加申込み(多対多の中間テーブル)。event_id + user_idでユニーク制約。現在の参加人数はstatus='applied'の行数をカウントする想定。取消し時に行を削除するかstatusを更新するかは実装フェーズで決定。※status更新方式(行を残す)を採る場合、ユニーク制約を`event_id + user_id`のままにすると取消し後の再申込みができなくなる(部分ユニークインデックス等の追加設計が必要になりうる。[要件定義書7節](./requirements.md#7-未決定事項tbd)参照) |

※以前の案にあった`RefreshToken`エンティティは、JWTのリフレッシュトークン運用のために設けていたものだったが、今回はLaravel標準のセッション認証(`web`ガード)を採用しJWTを実装しないため削除した。将来、外部API・モバイルクライアント向けに`api`ガード+JWTを追加する際は、その時点で改めてリフレッシュトークンの保存要否・設計を検討する([tech-stack.md](./tech-stack.md#認証)を参照)。

## 参加申込み・定員管理まわりの設計メモ

- `EventParticipation`はEventとUserの多対多を表す中間テーブル。主催者自身の行は作成しない(要件定義書[4.6](./requirements.md#46-参加申込み定員管理多対多リレーション)を参照)。
- 定員超過を防ぐための同時実行制御(行ロック・ユニーク制約+リトライ等の具体的な組み合わせ)は実装フェーズで設計する。
- 参加人数の集計方法(都度COUNT/非正規化カウンタ列を`Event`に持つ等)も実装フェーズで比較検討する。

## 関連ドキュメント

各画面とデータモデルの対応は[screen-design.md](./screen-design.md)を参照。
