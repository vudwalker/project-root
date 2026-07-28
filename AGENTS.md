# AGENTS.md

# プロジェクト概要

Laravelを使用したシフト管理Webアプリです。

現在はスタッフ画面の実装が完了し、PostgreSQLを利用したデータベース設計、認証、権限管理、管理画面、シフト編集機能を実装しています。

将来的にはSaaSとして複数企業で利用できる構成を目指します。

---

# 使用技術

- PHP
- Laravel
- Blade
- HTML
- CSS
- Vanilla JavaScript
- PostgreSQL
- Docker Compose
- pgAdmin

---

# 作業開始前

作業開始前に必ず以下を確認してください。

- AGENTS.md
- docs/specs/01_requirements.md
- docs/specs/02_database.md
- docs/specs/03_staff_ui.md
- docs/specs/04_admin_ui.md

また、現在のコードを確認し、

- 既存実装
- 仕様書
- 今回の依頼内容

に矛盾がないことを確認してください。

矛盾が見つかった場合は独断で実装を進めず、内容と影響範囲を報告してください。

---

# 実装方針

- Laravelの標準的な設計に従う
- Blade・CSS・Vanilla JavaScriptを使用する
- Controller、Service、Model、Bladeの責務を分離する
- データ取得はEloquentを利用する
- PostgreSQLを利用する
- 初期データはSeederで投入する
- 日本語でコメントを記述する
- 既存UIは原則変更しない
- 必要最小限のリファクタリングに留める
- 既存機能を壊さないことを優先する
- 一度に大規模な変更を行わない

---

# データ設計

シフトデータは唯一の原本とします。

店舗別画面
スタッフ別画面
スタッフ画面

は、同じシフトデータを表示軸だけ変更して表示します。

画面ごとに別のシフトデータを保持してはいけません。

Migration・Model・Relationは

docs/specs/02_database.md

を最優先してください。

---

# 権限

権限は以下の3種類です。

- スタッフ
- シフト管理者
- システム管理者

スタッフは自分が所属する店舗のシフトのみ閲覧できます。

シフト管理者は担当店舗のみ、

- シフト編集
- シフト配布
- 店舗情報編集
- 店舗別シフトパターン編集
- 主所属店舗が担当店舗であるスタッフ情報編集

を行えます。

システム管理者は全店舗を管理できます。

---

# UI仕様

UI仕様は以下を参照してください。

- docs/specs/03_staff_ui.md
- docs/specs/04_admin_ui.md

参考画像は

docs/specs/images/

配下を参照してください。

UIを変更する場合は仕様書と整合性を確認してください。

---

# コーディング規則

- Laravelの一般的な命名規則に従う
- PHPでは可能な限り型宣言を使用する
- JavaScriptのグローバル変数は使用しない
- CSSの色・サイズはCSS変数で管理する
- !importantは原則使用しない
- Bladeへ業務ロジックを書かない
- ControllerへHTMLを書かない
- Bladeで複雑な日付計算をしない
- 可読性を優先する

---

# 禁止事項

- Bootstrap
- Tailwind CSS
- Bulma
- Vue
- React
- Alpine.js
- jQuery
- npmへの依存
- フロントエンドビルド前提の実装
- UIを画像で再現すること
- 大量のインラインCSS

---

# Git運用

- Git commit を勝手に実行しない
- Git push を勝手に実行しない
- ブランチを勝手に作成・削除しない
- reset、rebase、merge、stashなど履歴を書き換える操作を勝手に実行しない
- コミットが必要な場合は、変更内容を説明したうえでユーザーの承認を得ること

---

# 完了前チェック

実装完了後は以下を確認してください。

- php artisan route:list
- PHP構文エラーがないこと
- Blade描画エラーがないこと
- 前月・翌月切替
- 5週・6週カレンダー
- うるう年
- 日本の祝日
- スマートフォン表示
- 店舗別画面の横スクロール
- 当日の背景色
- 既存UIとの差異がないこと

問題がある場合は内容を報告してください。