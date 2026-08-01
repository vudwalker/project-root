# Codex実装依頼：月次シフトメンバー管理

以下の調査結果と確定方針に従い、月次シフトメンバー管理機能を実装してください。

今回は、仕様を勝手に変更したり、別方式へ置き換えたりせず、既存コードへの影響を最小限に抑えてください。

## 目的

店舗所属スタッフと、各月のシフト編成対象スタッフを分離します。

例：

- 2026年8月：A・B・C・D・E
- 2026年9月：A・B・C・D
- 2026年10月：A・B・C・D・E

Eは店舗所属を解除するわけではなく、9月だけ月次シフトメンバーから外れます。

各月のメンバー構成は独立して保存し、ある月の変更が他月へ影響してはいけません。

## 採用する設計

### 既存テーブルの責務

- `store_user`
  - そのスタッフが、その店舗で勤務可能かを管理する
- `shift_schedule_users`
  - その店舗・対象月のシフト編成対象スタッフを管理する
- `shifts`
  - 実際の勤務割当を管理する
- `published_shifts`
  - 配布時点の公開スナップショットを管理する

`store_user_monthly_availability` のような月別例外方式は採用しません。

## Schema

### 新規テーブル

```text
shift_schedule_users
- id
- shift_schedule_id
- user_id
- display_order
- created_at
- updated_at
```

制約：

- `unique(shift_schedule_id, user_id)`
- `index(shift_schedule_id, display_order, user_id)`
- `shift_schedule_id -> shift_schedules.id`
- `user_id -> users.id`
- 外部キー削除方針は既存方針に合わせて `restrictOnDelete`
- `cascadeOnUpdate`
- `display_order` は整数
- `display_order` 自体には unique 制約を付けない

### shift_schedulesへの追加

```text
monthly_members_initialized_at timestamp nullable
monthly_members_version integer default 0
```

意味：

- `monthly_members_initialized_at = NULL`
  - 月次メンバー未初期化
- 日時あり
  - 初期化済み
  - 月次メンバーが0人でも初期化済みとして扱う

`monthly_members_version` は月次メンバー編集専用の楽観ロックに使用します。

既存の `draft_version` とは分離してください。

月次メンバー変更だけで、シフト再配布が必要な状態にしてはいけません。

## 月次メンバー初期化

月次メンバー管理画面を初めて開いた時に、その月を初期化します。

初期化対象は、次をすべて満たすスタッフです。

- 同一組織
- 対象店舗の `store_user.is_active = true`
- 対象月と `store_user.started_on` / `ended_on` の所属期間が重なる
- `users.status = active`
- `staff` ロールを持つ

並び順：

1. `store_user.display_order`
2. `users.name`
3. `users.id`

`shift_schedule_users.display_order` は0から連番で保存してください。

初期化は単一トランザクション内で行い、同時アクセス時の二重初期化を防いでください。

処理例：

1. `shift_schedule` を既存ロジックで取得または作成
2. 対象 `shift_schedules` 行を `lockForUpdate`
3. `monthly_members_initialized_at` を再確認
4. 未初期化の場合だけ候補スタッフを登録
5. `monthly_members_initialized_at` を保存
6. commit

既存の `store_id + target_month` unique制約と、`shift_schedule_id + user_id` unique制約を利用してください。

## 月次メンバー管理UI

独立画面として実装してください。

想定URL：

```text
/admin/shifts/stores/{store}/members?month=YYYY-MM
```

既存のシフト編集画面には、対象店舗・対象月を引き継ぐ「この月のスタッフを編集」リンクだけを追加してください。

スタッフ管理画面には入れないでください。

既存のシフトセル自動保存処理へ混在させず、月次メンバー管理専用のController、Request、Service、View、JavaScriptへ分離してください。

画面で必要な操作：

- 現在の月次メンバー一覧表示
- 追加候補の表示
- 月次メンバー追加
- 月次メンバー除外
- 表示順変更
- 保存中、保存済み、失敗、競合の表示

スマートフォンでも操作可能にしてください。

## 認可

月次メンバー編集権限は、既存の管理対象店舗権限を使用してください。

- `shift_manager`
  - `store_shift_manager` で担当している店舗のみ
- `system_admin`
  - 同一組織の全店舗
- `store_user`
  - 勤務可能店舗の判定にだけ使用
  - 管理権限の判定には使用しない

既存の `StorePolicy::editAdminShifts` を再利用できるか確認し、可能なら使用してください。

## 追加候補

月次メンバーへ新しく追加できるスタッフは、次をすべて満たす必要があります。

- 同一組織
- 対象店舗の有効な `store_user`
- 対象月と所属期間が重なる
- `users.status = active`
- `staff` ロールを持つ
- その `shift_schedule` の `shift_schedule_users` に未登録

`store_shift_manager` は候補条件に使用しないでください。

## 除外

月次メンバーから除外する場合、削除するのは `shift_schedule_users` の対象行だけです。

次は削除・変更しないでください。

- `shifts`
- `published_shifts`
- `users`
- `store_user`

既存シフトがあるスタッフは、月次メンバーから除外後もシフト編集画面に残してください。

## シフト編集画面の表示判定

表示対象は次の和集合です。

```text
月次表示スタッフ
∪
対象月に既存shiftsがあるスタッフ
```

状態ごとの挙動：

| 状態 | 表示 | 新規シフト追加 | 既存シフト変更・削除 |
|---|---|---|---|
| 月次メンバーかつ現在も勤務可能 | 可 | 可 | 可 |
| 月次メンバーだが店舗所属解除 | 可 | 不可 | 可 |
| 月次メンバーだが休職・退職・staffロール解除 | 可 | 不可 | 可 |
| 月次対象外かつ既存シフトなし | 不可 | 不可 | 対象なし |
| 月次対象外かつ既存シフトあり | 可 | 不可 | 可 |

既存の `can_create_shifts` の考え方を維持し、必要最小限の拡張で実現してください。

## 新規シフト登録

新規シフト登録時は、既存の店舗所属・在籍状態・staffロール判定に加えて、

```text
対象のshift_schedule_usersにuser_idが存在する
```

ことを必須にしてください。

ただし、月次対象外スタッフが既に持つシフトの変更・削除は従来どおり許可してください。

## 人数判定

配置人数判定は、月次メンバー一覧ではなく、その日に実際に存在する `shifts` を基準にしてください。

月次メンバーから除外されたスタッフでも、その日に既存シフトが残っている場合は人数判定へ含めてください。

今回発生している「31日以外がすべて×になる」不具合を、この機能実装と混同しないでください。

人数判定ロジックに不要な変更を加えないでください。

## 配布とスタッフ用画面

月次メンバーの追加・除外・並び替えによって、`published_shifts` を自動変更しないでください。

配布処理は従来どおり、現在の `shifts` から `published_shifts` を作成してください。

月次対象外スタッフでも既存 `shifts` がある場合は配布対象です。

スタッフ用画面は従来どおり `published_shifts` だけを読み、今回の実装では変更しないでください。

## 既存データ移行

既存の各 `shift_schedules` に対して、安全なBackfillを行ってください。

方針：

1. 現行画面で通常表示される有効な店舗所属スタッフを `shift_schedule_users` に登録
2. 現行の `store_user.display_order` 順で `display_order` を採番
3. 既存 `shifts` だけに存在する対象外スタッフは `shift_schedule_users` へ追加しない
4. そのスタッフはシフト編集画面で `shifts` との和集合により表示を維持する
5. Backfill済みの `shift_schedules.monthly_members_initialized_at` を設定する
6. 既存の `shifts` と `published_shifts` は変更しない

Migration直後に、現在の画面表示人数が意図せず変わらないようにしてください。

## 対象月範囲

現在コードが許可している対象月範囲を確認してください。

今回の業務要件は、現在月・翌月・翌々月の3か月を扱えることです。

現行コードが「現在月から3か月先まで」の4か月を許可している場合は、勝手に変更せず、差異を報告してください。

対象月範囲の仕様変更は今回の実装に含めないでください。

## テスト

最低限、次を追加してください。

- `shift_schedule_id + user_id` のunique制約
- 初期化が1回だけ行われる
- 月次メンバー0人を初期化済みとして保持できる
- 同時初期化で二重登録されない
- 初期化候補が同一組織・所属期間・active・staff条件を満たす
- 8月ABCDE、9月ABCD、10月ABCDEを独立して保持できる
- 9月変更が8月・10月へ影響しない
- 店舗所属変更が既存月次メンバーへ自動反映されない
- 休職・退職・staffロール解除後も既存月次メンバーと既存シフトを保持する
- 月次除外後も既存シフトを保持する
- 月次対象外で既存シフトなしならシフト表から消える
- 月次対象外で既存シフトありならシフト表に残る
- 月次対象外への新規シフト追加を拒否する
- 月次対象外の既存シフト変更・削除を許可する
- 並び替えでスタッフIDがずれない
- `monthly_members_version` の競合を検出する
- 月次メンバー変更で `draft_version` を増やさない
- 月次メンバー変更で再配布必要状態にならない
- 月次メンバー変更で `published_shifts` を自動変更しない
- 再配布時に現在の `shifts` から正しいスナップショットを作る
- shift_managerの担当外店舗を拒否する
- system_adminは同一組織の全店舗を操作できる
- スタッフ用画面が従来どおり `published_shifts` のみを読む

既存テストもすべて実行してください。

## 実装手順

一度に全体を大きく書き換えないでください。

次の順序で実装してください。

1. Migration・Model・Relation
2. Backfillと初期化Service
3. 月次メンバー管理Service・Request・Controller
4. 月次メンバー管理画面
5. シフト編集画面の読取処理変更
6. 新規シフト登録制限
7. テスト追加
8. 全テスト実行
9. 変更内容と残課題の報告

既存の共通Serviceを変更する場合は、本機能に必要な最小変更だけにしてください。

## 禁止事項

- 仕様書を勝手に変更しない
- `store_user_monthly_availability` 方式へ変更しない
- `store_user` の意味を変更しない
- `published_shifts` の構造を変更しない
- スタッフ用UIを変更しない
- 人数判定ロジックを不要に変更しない
- 既存シフトを自動削除しない
- 店舗所属を自動解除しない
- 月次メンバー変更を他月へ自動反映しない
- 対象月範囲を勝手に変更しない
- Seederを実行しない
- Git操作を行わない
- 実データを推測で補正しない
- テストを通すために仕様を変更しない

重大な矛盾、セキュリティ問題、データ損失の危険、既存仕様と両立できない問題を発見した場合は、実装を続けず、次を報告して停止してください。

- 問題箇所
- 現在の仕様
- 影響
- 最小の解決案

## 完了報告

完了時は次を報告してください。

1. 変更ファイル一覧
2. Migration内容
3. Backfill内容
4. 月次初期化の動作
5. 月次メンバー追加・除外・並び替えの動作
6. シフト編集画面への影響
7. 新規シフト制限
8. 配布・スタッフ画面への影響がないこと
9. 追加したテスト
10. 実行したテストと結果
11. 未解決事項
12. 手動確認が必要な画面項目
