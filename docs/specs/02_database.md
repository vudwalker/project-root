# データベース設計仕様

## 1. 目的

本書は、シフト管理Webアプリで使用するPostgreSQLのテーブル構造、リレーション、制約、保存方針を定義する。

MigrationやModelの実装前に、必ず以下を確認すること。

- `AGENTS.md`
- `docs/specs/01_requirements.md`
- `docs/specs/03_staff_ui.md`
- `docs/specs/04_admin_ui.md`
- 現在のMigration
- 現在のModel
- 現在のService
- 現在のSeeder
- 現在のシフトデータ生成・変換処理

本書と同等の責務を持つ既存実装がある場合は、重複したクラスやテーブルを新規作成せず、既存実装を優先して拡張する。

本書・既存コード・依頼内容に重大な矛盾がある場合は、Migrationを実行せずに報告する。

---

## 2. 基本方針

### 2.1 使用データベース

データベースはPostgreSQLを使用する。

開発環境ではDocker Compose上のPostgreSQLコンテナを使用する。

Laravelからの接続には`pdo_pgsql`を使用する。

### 2.2 Eloquentの使用

データ取得と保存にはEloquentを使用する。

Controllerには、SQL、複雑な集計処理、権限判定、画面用データ変換を直接書かない。

必要に応じて以下へ責務を分離する。

- Model
- Service
- Form Request
- Policy
- Query用クラス
- DTOまたはViewModel

### 2.3 シフトデータの原本

シフトデータは共通の原本として管理する。

以下の画面は、同じシフトデータを異なる軸に変換して表示する。

- 管理者用・店舗別シフト編集画面
- 管理者用・スタッフ別シフト確認画面
- スタッフ用個人カレンダー
- スタッフ用店舗別画面

画面ごとに独立したシフトデータを保存してはならない。

画面表示用に整形した配列やCollectionは、永続データとして保存しない。

### 2.3A 人物ID・権限・UIコンテキスト

一人の人物は一つの`users.id`で管理する。

`staff`、`shift_manager`、`system_admin`は人物を分けるIDではなく、利用可能な機能を表す独立した権限である。

- `shift_manager`は`staff`を暗黙に含まない。
- スタッフ兼シフト管理者は、同じ`users.id`へ両方のroleを明示的に付与する。
- `store_user`はスタッフとしての店舗所属を表す。
- `store_shift_manager`は管理者としての担当店舗を表す。
- これら二つの関係を相互に推測・自動生成しない。

UIは以下のコンテキストで分離する。

- `/staff`：`staff`権限、`published_shifts`、閲覧専用
- `/admin`：`shift_manager`または`system_admin`権限、`shifts`、編集・配布

同じ`users.id`が両方へアクセスできても、保存テーブルやUI責務を混在させない。

### 2.4 下書きと公開版

管理画面は、常に最新の下書きを表示する。

スタッフ用画面は、最後に配布された公開版だけを表示する。

配布後に下書きを編集しても、シフト管理者またはシステム管理者が再度「配布」を実行するまで、スタッフ用画面の内容は変更しない。

公開履歴は複数世代保持せず、店舗・対象月ごとに最新の公開版だけを保持する。

### 2.5 値の固定を避ける

権限、状態、シフトコード等をPostgreSQLのENUM型へ固定しない。

文字列カラム、マスタテーブル、CHECK制約、Laravel側のEnumクラス等を使用する。

### 2.6 削除と非表示

以下を区別する。

- 一時的または業務上利用しない  
  → `status`または`is_active`で管理
- 管理画面上から削除する  
  → 原則としてSoft Delete
- 関連データがなく完全削除しても問題ない  
  → システム管理者のみ物理削除可能

過去のシフトや所属履歴から参照されているデータは、物理削除しない。

---

## 3. テーブル一覧

### 3.1 業務テーブル

1. `organizations`
2. `stores`
3. `users`
4. `roles`
5. `role_user`
6. `store_user`
7. `store_shift_manager`
8. `store_shift_patterns`
9. `store_staffing_requirements`
10. `store_staffing_requirement_options`
11. `store_staffing_requirement_option_patterns`
12. `shift_schedules`
13. `shifts`
14. `published_shifts`

### 3.2 Laravel標準テーブル

必要に応じて以下を使用する。

- `password_reset_tokens`
- `sessions`
- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`

既存のLaravel標準Migrationがある場合は、重複して作成しない。

---

## 4. organizations

将来の複数企業対応を考慮した組織テーブル。

初期運用では1組織だけSeederで登録する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| name | varchar(255) | 不可 | 組織名 |
| code | varchar(100) | 不可 | 組織識別コード |
| is_active | boolean | 不可 | 利用可能状態 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |
| deleted_at | timestamp | 可 | 論理削除日時 |

制約：

- `code`は一意
- `is_active`の初期値は`true`
- Soft Deleteを使用する

リレーション：

```text
Organization
├── hasMany Stores
└── hasMany Users
```

---

## 5. stores

店舗情報を管理する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| organization_id | bigint | 不可 | 所属組織 |
| code | varchar(100) | 不可 | 店舗識別コード |
| name | varchar(255) | 不可 | 店舗名 |
| status | varchar(30) | 不可 | 店舗状態 |
| display_order | integer | 不可 | 店舗一覧の表示順 |
| staffing_check_mode | varchar(30) | 不可 | 人数チェック方式 |
| required_staff_count | integer | 可 | 固定必要人数 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |
| deleted_at | timestamp | 可 | 論理削除日時 |

`status`の想定値：

- `active`
- `inactive`

`active`は有効、`inactive`は無効を表す。

`suspended`、`closed`は現時点では使用しない。

`inactive`へ変更しても、関連する`shift_schedules`、`shifts`、`published_shifts`は削除しない。

`inactive`店舗では、新規シフト作成、シフト編集、配布を許可しない。

システム管理者は`inactive`店舗とその過去データを閲覧できる。

`staffing_check_mode`の想定値：

- `disabled`
- `fixed_total`
- `pattern_combinations`

制約：

- `organization_id + code`を一意にする
- `status`は`active`または`inactive`だけを許可する
- `display_order`の初期値は`0`
- `organization_id`は`organizations.id`への外部キー
- `required_staff_count`は0以上
- `fixed_total`の場合は`required_staff_count`を必須とする
- `pattern_combinations`の場合は`required_staff_count`を使用しない
- Soft Deleteを使用する

人数チェック：

- `disabled`
  - 人数判定を行わない
  - 現行の`DraftShiftWarningService`では人数警告を返さない
- `fixed_total`
  - DB上で`required_staff_count`を保持する
  - 現行の`DraftShiftWarningService`では人数比較を行わない
  - 人数警告を返さない
  - 現行の初期店舗設定では使用しない
- `pattern_combinations`
  - 日別のシフトパターン別人数と、店舗別の必要配置を比較する
  - 1つの必要配置に複数の選択肢がある場合は、いずれか1つを満たせば適合とする
  - 不一致の場合は不備とする
  - 配布時の検証対象とする

`pattern_combinations`では、日別・シフトパターンコード別に
重複しない`user_id`数を数える。

同一スタッフが同一勤務基準日に同じコードを複数持つ場合は1人として数え、
異なるコードを持つ場合は各コードで1人として数える。

現行の人数警告コード：

- 不足：`staffing_shortage`
- 超過：`staffing_excess`
- 必要配置または利用可能な選択肢がない：`staffing_requirement_missing`

これらはすべて`blocking`警告とする。

正式な店舗別必要配置は次のとおりとし、これら4店舗は
`staffing_check_mode = pattern_combinations`を使用する。

- 大安寺：C 1人
- 野田：C 1人
- 岡山富田：C 1人
- 西大寺：B 1人＋C 1人、またはD 1人

リレーション：

```text
Store
├── belongsTo Organization
├── belongsToMany Users through store_user
├── belongsToMany ShiftManagers through store_shift_manager
├── hasMany StoreShiftPatterns
├── hasMany StoreStaffingRequirements
└── hasMany ShiftSchedules
```

---

## 6. users

スタッフ、シフト管理者、システム管理者を共通管理する。

一人のユーザーが複数権限を持てる。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| organization_id | bigint | 不可 | 所属組織 |
| primary_store_id | bigint | 可 | 主所属店舗 |
| name | varchar(255) | 不可 | 氏名 |
| email | varchar(255) | 不可 | ログイン用メールアドレス |
| password | varchar(255) | 不可 | ハッシュ化パスワード |
| status | varchar(30) | 不可 | 在籍状態 |
| email_verified_at | timestamp | 可 | メール確認日時 |
| remember_token | varchar(100) | 可 | ログイン保持用 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |
| deleted_at | timestamp | 可 | 論理削除日時 |

`status`の想定値：

- `active`
- `on_leave`
- `retired`

制約：

- `email`をシステム全体で一意にする
- ログイン時はメールアドレスだけでユーザーを特定する
- `organization_id`は`organizations.id`への外部キー
- `primary_store_id`は`stores.id`への外部キー
- 主所属店舗は同一組織の店舗でなければならない
- スタッフ権限を持つ有効なユーザーには、原則として主所属店舗を設定する
- 主所属店舗は、そのユーザーの有効な所属店舗に含まれていなければならない
- 主所属店舗の整合性はアプリケーション側でも検証する
- Soft Deleteを使用する
- 過去シフトがあるユーザーは物理削除しない

リレーション：

```text
User
├── belongsTo Organization
├── belongsTo PrimaryStore
├── belongsToMany Roles
├── belongsToMany Stores through store_user
├── belongsToMany ManagedStores through store_shift_manager
├── hasMany Shifts
└── hasMany PublishedShifts
```

---

## 7. roles

権限マスタ。

初期データ：

- `staff`
- `shift_manager`
- `system_admin`

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| code | varchar(100) | 不可 | 権限コード |
| name | varchar(255) | 不可 | 表示名称 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `code`は一意

権限の意味：

### staff

- 自分のシフトを閲覧できる
- 自分が所属する店舗のシフトを閲覧できる
- 管理画面での編集はできない

### shift_manager

- `staff`権限を暗黙に含まない
- 担当店舗のシフトを閲覧・編集できる
- 担当店舗のシフトを配布できる
- 担当店舗情報とシフトパターンを追加・編集できる
- 主所属店舗が担当店舗であるスタッフ情報を追加・編集できる
- 担当外店舗は編集できない
- 管理者用・スタッフ別シフト確認画面では、選択した担当店舗へ有効な所属があるスタッフを閲覧できる
- 重複勤務確認のため、そのスタッフの対象月における全店舗のシフトを閲覧できる
- 担当外店舗のシフトは閲覧専用とする
- ユーザー権限およびシフト管理者の担当店舗割り当ては変更できない

### system_admin

- 全ての`active`店舗を閲覧・編集できる
- 全ての`active`店舗のシフトを配布できる
- `inactive`店舗とその過去データを閲覧できる
- `inactive`店舗では新規シフト作成、シフト編集、配布ができない
- 店舗・スタッフ・権限・担当店舗を管理できる
- ユーザー権限およびシフト管理者の担当店舗割り当てを変更できる

---

## 8. role_user

ユーザーと権限の中間テーブル。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| user_id | bigint | 不可 | ユーザーID |
| role_id | bigint | 不可 | 権限ID |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `user_id + role_id`を一意にする
- `user_id`は`users.id`への外部キー
- `role_id`は`roles.id`への外部キー

例：

```text
users.id = 15
role_user
├── staff
└── shift_manager

store_user
└── スタッフとして所属する店舗

store_shift_manager
└── 管理者として担当する店舗
```

`store_user`と`store_shift_manager`は同じユーザーを参照できるが、意味は別である。

---

## 9. store_user

スタッフの所属店舗を管理する。

スタッフは複数店舗へ所属できる。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_id | bigint | 不可 | 店舗ID |
| user_id | bigint | 不可 | スタッフID |
| display_order | integer | 不可 | 店舗内のスタッフ表示順 |
| is_active | boolean | 不可 | 現在有効な所属か |
| started_on | date | 可 | 所属開始日 |
| ended_on | date | 可 | 所属終了日 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `store_id + user_id`を一意にする
- `display_order`の初期値は`0`
- `is_active`の初期値は`true`
- `store_id`は`stores.id`への外部キー
- `user_id`は`users.id`への外部キー
- `ended_on`は`started_on`以降とする
- 主所属店舗は`users.primary_store_id`で管理する

店舗別シフト表のスタッフ行は、以下の順で並べる。

1. `store_user.display_order`
2. スタッフ名
3. ユーザーID

所属を解除しても、過去シフトを保持するためレコードを削除せず、原則として`is_active = false`にする。

---

## 10. store_shift_manager

シフト管理者の担当店舗を管理する。

スタッフとしての所属店舗とは別の関係として管理する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_id | bigint | 不可 | 担当店舗 |
| user_id | bigint | 不可 | シフト管理者 |
| is_active | boolean | 不可 | 現在有効な担当か |
| started_on | date | 可 | 担当開始日 |
| ended_on | date | 可 | 担当終了日 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `store_id + user_id`を一意にする
- 一人のシフト管理者は複数店舗を担当可能
- 有効なシフト管理者は、原則として1店舗につき1人
- `is_active = true`の`store_id`を一意にする部分インデックスを設定する
- `user_id`には`shift_manager`権限が必要
- システム管理者は、このテーブルに登録されていなくても全店舗を管理可能
- シフト操作は店舗状態の制限に従い、`inactive`店舗では過去データの閲覧だけを許可する
- 担当店舗の割り当て変更はシステム管理者のみ可能

権限判定：

```text
system_admin
→ 全店舗を管理可能
→ inactive店舗のシフトは過去データの閲覧だけが可能

shift_manager
→ store_shift_managerで有効な担当店舗のみ操作可能

staff
→ 管理画面の編集不可
```

---

## 11. 店舗別シフトパターンと必要配置

### 11.1 store_shift_patterns

店舗ごとのシフトパターンを管理する。

同じコードでも店舗ごとに勤務時間が異なるため、店舗別に独立したレコードとして保持する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_id | bigint | 不可 | 店舗ID |
| code | varchar(20) | 不可 | シフトコード |
| work_minutes | integer | 不可 | 勤務時間数 |
| start_time | time | 可 | 勤務開始時刻 |
| start_day_offset | smallint | 可 | 勤務基準日から開始日までの日数 |
| end_time | time | 可 | 勤務終了時刻 |
| end_day_offset | smallint | 可 | 勤務基準日から終了日までの日数 |
| display_order | integer | 不可 | 入力ボタンの表示順 |
| is_active | boolean | 不可 | 現在使用可能か |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |
| deleted_at | timestamp | 可 | 論理削除日時 |

初期コード：

- A
- B
- C
- D
- E
- 研
- 有

制約：

- `store_id + code`を一意にする
- `work_minutes`は0以上
- `display_order`の初期値は`0`
- `is_active`の初期値は`false`
- 勤務時間帯の4項目はすべてNULL、またはすべて値ありとする
- `start_day_offset`と`end_day_offset`は`0`または`1`とする
- `start_day_offset`は`end_day_offset`以下とする
- 入力ボタンには`is_active = true`のパターンだけを表示する
- Soft Deleteを使用する
- 使用済みパターンは物理削除しない

勤務時間数は分単位で保存する。

例：

```text
7時間30分
→ 450
```

`研`は勤務時間が不定のため、初期値を0分とする。

`有`は運用ルールが確定するまで、初期値を0分とする。

全店舗についてA、B、C、D、E、研、有のレコードをSeederで作成し、店舗ごとに`is_active`と`work_minutes`を設定する。

同じCであっても、店舗ごとに別の勤務時間を設定できる。

```text
大安寺 C
→ 20:00から翌08:00

野田 C
→ 20:00から翌08:00

岡山富田 C
→ 20:00から翌08:00

西大寺 C
→ 翌02:00から翌08:00
```

必要配置も店舗別に保持し、現在の初期設定は次のとおりとする。

- 大安寺：C 1人
- 野田：C 1人
- 岡山富田：C 1人
- 西大寺：B 1人＋C 1人、またはD 1人

勤務時間帯と`work_minutes`は別の値として扱い、勤務時間帯から
既存の`work_minutes`を自動更新しない。

### 11.2 store_staffing_requirements

店舗・勤務基準日ごとに適用する必要配置ルールを管理する。

日付指定、曜日指定、全日共通のいずれかとして保持でき、
有効期間内で対象日に適用されるルールを選択する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_id | bigint | 不可 | 店舗ID |
| work_date | date | 可 | 特定の勤務基準日 |
| weekday | smallint | 可 | 曜日。0を日曜日、6を土曜日とする |
| effective_from | date | 可 | 適用開始日 |
| effective_to | date | 可 | 適用終了日 |
| is_active | boolean | 不可 | 現在有効か |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |
| deleted_at | timestamp | 可 | 論理削除日時 |

制約：

- `store_id`は`stores.id`への外部キー
- 店舗を削除する場合も必要配置が存在すれば削除を拒否する
- `work_date`と`weekday`を同時に設定しない
- `weekday`は`0`から`6`までとする
- `effective_to`は`effective_from`以降とする
- `is_active`の初期値は`true`
- Soft Deleteを使用する

同一日に複数の有効なルールが該当する場合は、
特定日、曜日、全日共通の順に優先する。

同じ優先度では、`effective_from`が新しいルールを優先し、
さらに同じ場合は新しいレコードを優先する。

### 11.3 store_staffing_requirement_options

1件の必要配置ルールを満たす選択肢を管理する。

同じ必要配置ルールに複数の選択肢がある場合はOR条件とし、
いずれか1つの選択肢を満たせば適合とする。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_staffing_requirement_id | bigint | 不可 | 必要配置ルールID |
| code | varchar(50) | 不可 | 選択肢コード |
| display_order | smallint | 不可 | 選択肢の表示・評価順 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `store_staffing_requirement_id`は`store_staffing_requirements.id`への外部キー
- 必要配置ルールを削除する場合は、その選択肢も削除する
- `store_staffing_requirement_id + code`を一意にする
- `display_order`は0以上とし、初期値は`0`とする

### 11.4 store_staffing_requirement_option_patterns

1つの必要配置選択肢に必要なシフトパターンと人数を管理する。

同じ選択肢内の複数レコードはAND条件とする。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_staffing_requirement_option_id | bigint | 不可 | 必要配置選択肢ID |
| store_shift_pattern_id | bigint | 不可 | 店舗別シフトパターンID |
| required_count | smallint | 不可 | 必要人数 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `store_staffing_requirement_option_id`は`store_staffing_requirement_options.id`への外部キー
- 必要配置選択肢を削除する場合は、そのパターン別人数も削除する
- `store_shift_pattern_id`は`store_shift_patterns.id`への外部キー
- 使用中の店舗別シフトパターンは削除を拒否する
- `store_staffing_requirement_option_id + store_shift_pattern_id`を一意にする
- `required_count`は0以上とする
- 参照するシフトパターンは、必要配置ルールと同じ店舗のものに限る

`pattern_combinations`の正式な初期データは次のように保持する。

| 店舗 | 選択肢 | 必要パターン |
|---|---|---|
| 大安寺 | full-c | C 1人 |
| 野田 | full-c | C 1人 |
| 岡山富田 | full-c | C 1人 |
| 西大寺 | split-b-c | B 1人＋C 1人 |
| 西大寺 | full-d | D 1人 |

---

## 12. shift_schedules

店舗・対象月単位のシフト管理レコード。

下書きと公開状態のメタデータを保持する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| store_id | bigint | 不可 | 対象店舗 |
| target_month | date | 不可 | 対象月の月初日 |
| draft_version | bigint | 不可 | 下書きバージョン |
| published_version | bigint | 可 | 最終配布したバージョン |
| shift_updated_at | timestamp | 可 | シフト内容の最終更新日時 |
| published_at | timestamp | 可 | 最終配布日時 |
| published_by | bigint | 可 | 最終配布者 |
| created_by | bigint | 可 | 作成者 |
| updated_by | bigint | 可 | 最終更新者 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | Laravel標準更新日時 |

制約：

- `store_id + target_month`を一意にする
- `target_month`には月初日を保存する
- `draft_version`の初期値は`0`
- `published_version`がNULLなら未配布
- `draft_version`と`published_version`は0以上
- `published_by`、`created_by`、`updated_by`は`users.id`への外部キー

状態判定：

```text
未配布
→ published_versionがNULL

配布済み
→ published_version = draft_version

再配布が必要
→ published_version < draft_version
```

`updated_at`を業務上のシフト最終更新日時として使用しない。

シフト内容が追加・変更・削除された場合だけ、`shift_updated_at`を更新する。

---

## 13. shifts

管理画面で編集する最新の下書きシフト。

同一スタッフが同一勤務基準日・同一店舗で複数シフトを持つことは
DB上および下書き保存では許可するが、重複勤務として警告する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| shift_schedule_id | bigint | 不可 | 店舗・対象月の管理レコード |
| user_id | bigint | 不可 | スタッフID |
| work_date | date | 不可 | 勤務基準日 |
| store_shift_pattern_id | bigint | 不可 | 店舗別シフトパターン |
| sequence | smallint | 不可 | 同一セル内の表示順 |
| entry_uuid | uuid | 不可 | 追加操作を一意に識別するUUID |
| pattern_code | varchar(20) | 不可 | 保存時点のコード |
| work_minutes | integer | 不可 | 保存時点の勤務時間数 |
| created_by | bigint | 可 | 入力者 |
| updated_by | bigint | 可 | 最終更新者 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `shift_schedule_id + user_id + work_date + sequence`を一意にする
- `entry_uuid`をシステム全体で一意にする
- `sequence`は1以上
- `work_minutes`は0以上
- `work_date`は`shift_schedule.target_month`と同じ月でなければならない
- シフトパターンは対象店舗のパターンでなければならない
- スタッフは対象店舗へ所属していなければならない
- 同日・同一店舗の複数シフトを許可する
- 同一コードの複数シフトを許可する
- 同日・異なる店舗への登録もDB上は許可する
- 同一スタッフが同一勤務基準日に2件以上の下書きシフトを持つ場合は、店舗の同異を問わずアプリケーション側で警告する
- 同一勤務基準日の重複勤務が残る場合は配布不可

同一セルに2シフトを保存できる例：

| 店舗 | 日付 | スタッフ | コード | sequence |
|---|---|---|---|---:|
| 大安寺 | 2026-07-10 | 近澤幸次 | A | 1 |
| 大安寺 | 2026-07-10 | 近澤幸次 | C | 2 |

重複勤務の判定：

```text
同一スタッフ
かつ同一勤務基準日（work_date）
かつ下書きシフトが2件以上
→ 重複勤務
```

警告コード：

- 関係する下書きシフトが同一店舗内だけの場合：`same_store_duplicate`
- 関係する下書きシフトに異なる店舗を含む場合：`cross_store_duplicate`

どちらも`blocking`警告とする。

重複勤務があっても下書き保存は許可し、
重複勤務が1件でも残っている場合は配布を許可しない。

`pattern_code`と`work_minutes`には、シフト入力時点の店舗別シフトパターンを複写する。

後から店舗別シフトパターンを変更しても、既存シフトのコードや勤務時間を自動変更しない。

同一セルへ新規シフトを追加する場合は、現在の最大`sequence`より後ろへ追加する。

各シフトは個別に削除できるようにする。

個別削除後は、同一セルに残ったシフトの`sequence`を1からの連番へ詰め直す。

対象シフトの削除と`sequence`の詰め直しは、同じトランザクション内で行う。

ドラッグによる並べ替えは今回実装しない。

順番を変更する場合は、対象シフトを削除して再入力する。

### 13.1 管理画面の月間計

月間計は、保存済みの集計値ではなく、管理画面に表示する対象月・スタッフ単位の下書き`shifts`から以下を算出する。

- 勤務時間：`work_minutes`の合計
- A〜E：`pattern_code`が各コードと一致するシフト件数
- 総数：A〜E、研、有を含む全シフト件数

同一日に複数シフトがある場合も、それぞれを1件として集計する。

店舗別管理画面では、選択店舗の下書き`shifts`だけを集計する。

スタッフ別管理画面では、画面に表示している全店舗の下書き`shifts`をスタッフ単位で集計する。

勤務時間はDB上では`work_minutes`の合計として扱い、画面では累積時間の`HH:MM`形式で表示する。

24時間を超えても折り返さず、2250分は`37:30`と表示する。

月間計を別の原本データとして保存せず、`shifts`から算出する。

---

## 14. published_shifts

スタッフ用画面に表示する最新の公開版。

配布時に、対象店舗・対象月の`shifts`をコピーして作成する。

| カラム | 型 | NULL | 説明 |
|---|---|---:|---|
| id | bigint | 不可 | 主キー |
| shift_schedule_id | bigint | 不可 | 店舗・対象月の管理レコード |
| user_id | bigint | 不可 | スタッフID |
| work_date | date | 不可 | 勤務日 |
| sequence | smallint | 不可 | 同一セル内の表示順 |
| pattern_code | varchar(20) | 不可 | 配布時点のコード |
| work_minutes | integer | 不可 | 配布時点の勤務時間数 |
| published_at | timestamp | 不可 | 配布日時 |
| created_at | timestamp | 不可 | 作成日時 |
| updated_at | timestamp | 不可 | 更新日時 |

制約：

- `shift_schedule_id + user_id + work_date + sequence`を一意にする
- 店舗別シフトパターンへの外部キーは持たない
- 配布時点のコードと勤務時間数をスナップショットとして保持する
- 再配布時は、その`shift_schedule_id`の既存公開版を置き換える
- 公開版の置換はトランザクション内で行う

スタッフ用画面は`published_shifts`だけを参照し、下書きの`shifts`を直接参照しない。

過去の`published_shifts`に対応する店舗が`inactive`へ変更されても、公開シフトは削除しない。

スタッフの個人シフトでは、過去の公開シフトに含まれる`inactive`店舗名を表示してよい。

未配布の月には、以下のような案内を表示する。

```text
この月のシフトはまだ配布されていません。
```

スタッフ用画面の最終配布日時には、`shift_schedules.published_at`を使用する。

---

## 15. 自動保存

管理者用・店舗別シフト編集画面のセル入力と削除は、`fetch()`を使用して非同期保存する。

保存APIは以下を実行する。

1. ログイン確認
2. 権限確認
3. 担当店舗確認
4. 店舗が`active`であることを確認
5. 対象月確認
6. シフトパターン確認
7. スタッフの所属店舗確認
8. `entry_uuid`確認
9. シフトの追加・更新・削除
10. `draft_version`を加算
11. `shift_updated_at`を更新
12. `updated_by`を更新
13. 重複勤務を再判定
14. 保存結果をJSONで返す

同一スタッフ・同一勤務基準日の重複勤務がある場合も、
同一店舗・異店舗を問わず下書き保存自体は許可する。

保存後に`same_store_duplicate`または`cross_store_duplicate`を再判定し、
`blocking`警告として返す。

同一セルへの新規シフトは`sequence`の末尾へ追加する。

各シフトの削除は、対象シフトを識別して個別に保存する。

削除後は、残ったシフトの`sequence`を1からの連番へ詰め直す。

削除と`sequence`の詰め直しは、同じトランザクション内で行う。

クライアントは、意図的な追加操作ごとに新しい`entry_uuid`を発行する。

通信再試行時は同じ`entry_uuid`を再利用する。

同じ`entry_uuid`を持つ登録済みシフトがある場合は、新しいシフト行を追加せず、同じ追加操作を一度だけ処理する。

同一コードの複数登録は、異なる`entry_uuid`を持つ別の追加操作として許可する。

複数のセル変更は、500〜1000ms程度のdebounce後にまとめて送信する。

保存失敗時は、画面上の入力内容を消さずに保持する。

レスポンスには最低限、以下を含める。

- 保存成功・失敗
- 最新の`draft_version`
- 保存日時
- 重複勤務情報
- 人数チェック結果
- エラーメッセージ

楽観ロックを使用する場合は、リクエストに画面側の`draft_version`を含める。

DB上の`draft_version`と一致しない場合は、無条件に上書きせず競合として返す。

---

## 16. 配布

配布機能は今回の実装範囲に含める。

配布処理では、対象店舗・対象月の下書きを検証し、
問題がなければ公開版へ反映する。

配布時の想定処理：

1. ログイン確認
2. 配布権限確認
3. 店舗が`active`であることを確認
4. 対象店舗・対象月の下書き取得
5. 未保存変更がないことを確認
6. 同一スタッフの同一勤務基準日に2件以上の下書きシフトがないことを、店舗の同異を問わず検証
7. `staffing_check_mode = pattern_combinations`の場合は必要シフトパターンの組み合わせを検証
8. 不備があれば配布を中止
9. 既存の`published_shifts`を削除
10. 最新の`shifts`を`published_shifts`へコピー
11. `published_version = draft_version`へ更新
12. `published_at`と`published_by`を更新
13. トランザクションを確定

強制配布機能は設けない。

`same_store_duplicate`または`cross_store_duplicate`が1件でも残っている場合は配布不可とする。

`pattern_combinations`では、店舗別に登録された必要配置の選択肢を再検証し、
いずれの選択肢も満たさない日が1日でもある場合は配布不可とする。

配布後に下書きを変更した場合は、以下の状態になる。

```text
published_version < draft_version
```

この場合、管理画面には「再配布が必要」と表示する。

スタッフ用画面には、再配布されるまで編集前の公開版を表示する。

---

## 17. 削除方針

### 17.1 状態変更

業務上利用しない状態は、`status`または`is_active`で管理する。

例：

- スタッフの休職・退職
- 店舗の休止・閉鎖
- 店舗所属の終了
- シフト管理担当の終了
- シフトパターンの無効化

### 17.2 論理削除

以下は原則としてSoft Deleteを使用する。

- `organizations`
- `stores`
- `users`
- `store_shift_patterns`
- `store_staffing_requirements`

### 17.3 物理削除

関連データが一切存在しない場合だけ、システム管理者が物理削除できる。

以下から参照されているデータは物理削除しない。

- シフト
- 公開シフト
- 所属履歴
- 担当履歴
- 配布記録
- 必要配置ルール

---

## 18. インデックス

最低限、以下を設定する。

### organizations

- `code`

### stores

- `organization_id`
- `organization_id + code`
- `status`
- `display_order`

### users

- `organization_id`
- `email`
- `primary_store_id`
- `status`

### role_user

- `user_id + role_id`

### store_user

- `store_id + user_id`
- `store_id + is_active + display_order`
- `user_id + is_active`

### store_shift_manager

- `store_id + user_id`
- `store_id + is_active`
- `user_id + is_active`

### store_shift_patterns

- `store_id + code`
- `store_id + is_active + display_order`

### store_staffing_requirements

- `store_id + is_active + work_date`
- `store_id + is_active + weekday`
- `store_id + effective_from + effective_to`

### store_staffing_requirement_options

- `store_staffing_requirement_id + code`

### store_staffing_requirement_option_patterns

- `store_staffing_requirement_option_id + store_shift_pattern_id`

### shift_schedules

- `store_id + target_month`
- `target_month`
- `published_at`

### shifts

- `shift_schedule_id`
- `entry_uuid`
- `user_id + work_date`
- `work_date`
- `store_shift_pattern_id`

### published_shifts

- `shift_schedule_id`
- `user_id + work_date`
- `work_date`

重複勤務判定で頻繁に使用するため、`user_id + work_date`の複合インデックスは必須とする。

---

## 19. Seeder

初期データはSeederで投入する。

Seederには以下を含める。

- 初期組織1件
- 大安寺
- 野田
- 西大寺
- 岡山富田
- 参考画像に存在するスタッフ
- 権限
  - `staff`
  - `shift_manager`
  - `system_admin`
- ユーザーへの複数権限割り当て
- スタッフの所属店舗
- 主所属店舗
- 店舗ごとのスタッフ表示順
- シフト管理者の担当店舗
- 店舗別シフトパターン
  - A
  - B
  - C
  - D
  - E
  - 研
  - 有
- 店舗別シフトパターンの勤務時間帯
- `staffing_check_mode = pattern_combinations`
- 店舗別必要配置
  - 大安寺：C 1人
  - 野田：C 1人
  - 岡山富田：C 1人
  - 西大寺：B 1人＋C 1人、またはD 1人
- 参考画像に対応する下書きシフト
- 動作確認用の公開シフト
- システム全体で重複しないダミーのメールアドレス
- ダミーのパスワード

パスワードはLaravelのHash機能でハッシュ化する。

Seederは可能な範囲で再実行可能な構造にする。

開発中は以下の実行を許可する。

```bash
php artisan migrate:fresh --seed
```

ただし、Agentはユーザーの承認前に実行してはならない。

---

## 20. Migration作成順

外部キー依存関係を考慮し、原則として以下の順で作成する。

1. `organizations`
2. `stores`
3. `users`
4. `roles`
5. `role_user`
6. `store_user`
7. `store_shift_manager`
8. `store_shift_patterns`
9. `shift_schedules`
10. `shifts`
11. `published_shifts`
12. `store_staffing_requirements`
13. `store_staffing_requirement_options`
14. `store_staffing_requirement_option_patterns`
15. Laravel標準テーブル

既存Migrationがある場合は、新規作成前に内容と依存関係を確認する。

特に既存の`users`テーブルがある場合は、別の`users`テーブルを作成せず、既存Migrationを変更または追加Migrationで拡張する。

既存の`users.email`の一意制約は維持する。

`organization_id + email`の複合一意制約は作成しない。

---

## 21. Migration実行前の報告

Migrationを実行する前に、Agentは以下を報告する。

- 現在存在するMigration
- 新規作成するMigration
- 変更するMigration
- テーブル一覧
- カラム一覧
- 外部キー
- 一意制約
- CHECK制約
- インデックス
- Soft Delete対象
- 既存コードへの影響
- 既存モックデータ処理への影響
- 想定されるリスク

ユーザーの承認前に、以下を実行してはならない。

```bash
php artisan migrate
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

---

## 22. 完了確認

実装後は、ユーザーの承認を得たうえで以下を確認する。

```bash
php artisan migrate:fresh --seed
php artisan migrate:status
php artisan route:list
```

確認事項：

- PostgreSQLへ正常に接続できる
- 全Migrationが成功する
- Seederが成功する
- 本物のログイン認証が動作する
- メールアドレスだけでログインできる
- `users.email`がシステム全体で一意になる
- 組織が異なる場合も重複メールアドレスを登録できない
- 一人のユーザーが複数権限を持てる
- スタッフが複数店舗へ所属できる
- 主所属店舗を設定できる
- 店舗ごとのスタッフ表示順を保持できる
- シフト管理者が複数店舗を担当できる
- シフト管理者が選択した担当店舗へ有効な所属があるスタッフを表示できる
- シフト管理者がそのスタッフの対象月における全店舗シフトを閲覧できる
- シフト管理者が担当外店舗のシフトを編集できない
- シフト管理者がユーザー権限や担当店舗割り当てを変更できない
- システム管理者だけがユーザー権限と担当店舗割り当てを変更できる
- 担当外店舗の編集を拒否できる
- システム管理者が全ての`active`店舗のシフトを編集できる
- 店舗ごとのシフトパターンを保持できる
- 同じコードでも店舗ごとに勤務時間を変更できる
- 店舗ごとのシフトパターンに勤務開始・終了時刻と日オフセットを保持できる
- 無効なシフトパターンが入力ボタンに表示されない
- 同一勤務基準日・同一店舗の複数シフトを下書き保存できる
- 同一勤務基準日・同一店舗の2件以上を`same_store_duplicate`として検出できる
- 同一コードを同一セルへ複数登録できる
- 新規シフトを`sequence`の末尾へ追加できる
- 各シフトを個別に削除できる
- 個別削除後に`sequence`を1からの連番へ詰め直せる
- 削除と`sequence`の詰め直しを同じトランザクションで実行できる
- 意図的な追加ごとに異なる`entry_uuid`を登録できる
- 再試行時に同じ`entry_uuid`を使用してもシフト行が増えない
- 二重送信による意図しない重複登録を防止できる
- 同一勤務基準日・異なる店舗のシフトを下書き保存できる
- 同一勤務基準日・異なる店舗の2件以上を`cross_store_duplicate`として検出できる
- `same_store_duplicate`と`cross_store_duplicate`を`blocking`警告として扱える
- 重複勤務が残る場合に配布不可と判定できる
- `disabled`では人数警告を返さない
- `pattern_combinations`で日別・コード別のスタッフ数を重複なしで集計できる
- `staffing_check_mode`で`disabled`、`fixed_total`、`pattern_combinations`を保持できる
- `fixed_total`では現行の人数警告判定を行わない
- 大安寺、野田、岡山富田でC 1人を必要配置として保持・判定できる
- 西大寺でB 1人＋C 1人、またはD 1人を必要配置として保持・判定できる
- `pattern_combinations`で複数選択肢のいずれかを満たせば適合と判定できる
- 必要配置不備を`blocking`警告として扱える
- 下書きと公開版が分離される
- 配布後の編集が公開版へ即時反映されない
- `published_version < draft_version`で再配布必要を判定できる
- シフト最終更新日時を取得できる
- 最終配布日時を取得できる
- 配布時に下書きを検証し、公開版へ反映できる
- 店舗別管理画面で選択店舗の下書きだけから月間計を算出できる
- スタッフ別管理画面で表示対象となる全店舗の下書きから月間計を算出できる
- 勤務時間を24時間で折り返さない`HH:MM`形式で表示できる
- 2250分を`37:30`と表示できる
- システム管理者が`inactive`店舗と過去データを閲覧できる
- `inactive`店舗で新規シフト作成、編集、配布を拒否できる
- `inactive`店舗へ変更しても過去の下書きと公開シフトを保持できる
- 開発用Seederへ大安寺、野田、西大寺、岡山富田を登録できる
- 既存スタッフ用画面のUIとスクロール動作が壊れていない
