@extends('layouts.admin')

@section('title', '管理対象店舗なし｜管理画面')

@section('content')
    <section class="admin-shift-workspace" aria-labelledby="no-store-title">
        <h1 id="no-store-title" class="admin-visually-hidden">管理対象店舗がありません</h1>
        <div class="admin-screen-status" role="status">
            管理対象店舗がありません。担当店舗の設定をシステム管理者へ確認してください。
        </div>
    </section>
@endsection
