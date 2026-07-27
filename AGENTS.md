# AGENTS.md

## プロジェクト概要

Laravelを使用したシフト管理Webアプリです。

現在はスタッフ用画面のUIとカレンダー機能を実装します。
将来はPostgreSQL、認証、権限管理、シフト編集、管理者画面を追加します。

## 使用技術

- PHP
- Laravel
- Blade
- HTML
- CSS
- Vanilla JavaScript
- 将来のデータベースはPostgreSQL

## 禁止事項

- CSSフレームワークを使用しない
- Bootstrap、Tailwind CSS、Bulmaを使用しない
- Vue、React、Alpine.js、jQueryを使用しない
- 画像を背景としてUIを再現しない
- npmやフロントエンドビルド処理へ依存しない
- 大量のインラインCSSを使用しない
- Controller内にHTMLを書かない
- Blade内で複雑な日付計算をしない

## 実装方針

- Laravel上で実装する
- Blade、CSS、Vanilla JavaScriptを使用する
- Controller、Service、Bladeの責務を分離する
- カレンダー計算はServiceで行う
- 現在はモックデータを使用する
- 将来Eloquentへ置き換えやすいデータ構造にする
- 店舗名、スタッフ名、年月、曜日、シフトコードをBladeへ固定記述しない
- シフトコードをCに固定しない
- 日本語でコメントと説明を書く

## UI仕様

スタッフ用画面の詳細仕様は、次のファイルを参照してください。

- `docs/specs/staff-shift-ui.md`

参考画像：

- `docs/specs/images/staff_top.png`
- `docs/specs/images/staff_tenpobetsu1.png`
- `docs/specs/images/staff_tenpobetsu2.png`

実装前に仕様書と参考画像を確認してください。

## コーディング規則

- PHPはLaravelの一般的な命名規則に従う
- PHPファイルでは可能な範囲で型宣言を使用する
- JavaScriptはグローバル変数を避ける
- CSSの色と寸法はCSS変数で管理する
- `!important`は原則使用しない
- アクセシビリティを損なわない
- JavaScriptが無効でも主要なリンクは機能させる

## 完了前の確認

実装後は以下を確認してください。

- `php artisan route:list`
- PHP構文エラーがないこと
- Bladeの描画エラーがないこと
- 前月・翌月が正しく動作すること
- 5週と6週のカレンダーを確認すること
- うるう年を確認すること
- スマートフォン表示を確認すること
- 店舗別表の横スクロールを確認すること
- 当日の背景色を確認すること
- 日本の祝日表示を確認すること