# MeetHub データ設計たたき台

[要件定義書](./requirements.md)へ戻る

詳細設計に入る前のたたき台であり、詳細なER図・インデックス設計・カラムの型/制約の最終決定は実装フェーズで別途整理する。

## 主要エンティティ

| エンティティ | 主なカラム | 備考 |
|---|---|---|
| User | id, username, email, password_hash, display_name, bio, avatar_url, created_at | bioは自己紹介(任意、1000文字まで)。avatar_urlはEventのimage_urlと同じく、公開URLではなくディスク上の保存パス(例: `avatars/xxx.png`)を保存し、Userモデルのアクセサで公開URLに変換して返す。アイコンの差し替え・削除・退会時は画像ファイルも削除する |
| Event | id, organizer_id(FK→User), title, description, location, starts_at, capacity, image_url, created_at, updated_at | organizer_idがイベント主催者。capacityは定員(1以上の整数、NOT NULL。無制限[null]は許容しないことをIssue #8で決定)。image_urlには公開URLではなく、ディスク上の保存パス(例: `events/xxx.jpg`)を保存し、Eventモデルのアクセサで現在のディスク(`FILESYSTEM_DISK`)の公開URLに変換して返す(ローカル/S3の切り替えでDBの値を書き換えずに済むようにするため)。イベント削除時は画像ファイルも削除する(ただし下記の主催者アカウント削除による連鎖削除ではモデルイベントが発火しないため、画像ファイルは残る)。organizer_idは`ON DELETE CASCADE`とし、**主催者がアカウントを削除した場合、そのユーザーが主催する全イベント(および紐づくコメント・いいね・参加申込み)も連鎖削除される**(意図した仕様。他の参加者への通知は行わない。今回の課題規模では許容する判断とした) |
| EventLike | id, event_id(FK), user_id(FK), created_at | 「興味あり」。event_id + user_idでユニーク制約。付け外しのみで更新しないためupdated_atは持たない。event_id・user_idとも`ON DELETE CASCADE` |
| Comment | id, event_id(FK), user_id(FK), body, created_at | bodyは1000文字以内(Issue #12で決定)。編集機能を持たないためupdated_atは持たない。削除は投稿者本人のみ(イベント主催者も他人のコメントは削除不可)。event_id・user_idとも`ON DELETE CASCADE` |
| Follow | id, follower_id(FK→User), followee_id(FK→User), created_at | follower_id + followee_idでユニーク制約 |
| EventParticipation | id, event_id(FK), user_id(FK), created_at | 参加申込み(多対多の中間テーブル)。event_id + user_idでユニーク制約。**取消し時は行を削除する**(Issue #16で決定。status更新方式は不採用のため、status・cancelled_atカラムは持たない)。行が残らないため、ユニーク制約のままで取消し後の再申込みができる。申込み・削除のみで更新しないためupdated_atは持たない。現在の参加人数は、そのイベントの行数をCOUNTする。event_id・user_idとも`ON DELETE CASCADE` |

※以前の案にあった`RefreshToken`エンティティは、JWTのリフレッシュトークン運用のために設けていたものだったが、今回はLaravel標準のセッション認証(`web`ガード)を採用しJWTを実装しないため削除した。将来、外部API・モバイルクライアント向けに`api`ガード+JWTを追加する際は、その時点で改めてリフレッシュトークンの保存要否・設計を検討する([tech-stack.md](./tech-stack.md#認証)を参照)。

## 参加申込み・定員管理まわりの設計メモ

- `EventParticipation`はEventとUserの多対多を表す中間テーブル。主催者自身の行は作成しない(要件定義書[4.6](./requirements.md#46-参加申込み定員管理多対多リレーション)を参照)。
- 定員超過を防ぐための同時実行制御は、**都度COUNT + 悲観ロック**とする(Issue #16で決定)。申込み処理(`Event::join`)はトランザクション内で対象`Event`の行を`SELECT ... FOR UPDATE`(`lockForUpdate()`)でロックし、そのイベントの`EventParticipation`をCOUNTして定員未満であることを確認してから行を追加する。同じイベントへの申込みはこのロックで直列化されるため、COUNTと登録の間に他の申込みが割り込まない。
- 参加人数の集計は都度COUNTとし、非正規化カウンタ列は`Event`に持たない(カウンタとの不整合を避けるため。小規模コミュニティ向けで1イベントあたりの参加人数は多くないため、COUNTのコストは問題にならない)。
- 重複申込みは、上記ロック内での存在チェックに加え、`event_id + user_id`のユニーク制約でも防ぐ。
- 取消し(行の削除)は参加人数が減るだけで定員を超過しないため、ロックは取らない。
- イベント編集で定員を変更する際も、同じイベント行をロックしてから参加人数をCOUNTし、定員が参加人数を下回らないことを確認してから保存する(定員の変更と申込みが同時に行われても、定員未満の参加人数に減ってしまわないようにするため)。
- 同時実行制御のテスト(`tests/Concurrency`)は、SQLiteではなくPostgreSQL上で、別プロセスから同時に申込みを行って検証する(`phpunit.pgsql.xml`)。

## 関連ドキュメント

各画面とデータモデルの対応は[screen-design.md](./screen-design.md)を参照。
