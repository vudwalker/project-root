@extends('layouts.admin')

@section('title', '店舗追加｜管理画面')

@section('content')
    <section class="admin-store-management" aria-labelledby="admin-store-create-title">
        <header class="admin-store-management__header">
            <div>
                <h1 id="admin-store-create-title">店舗追加</h1>
                <p>作成後に所属スタッフやシフト設定を登録します。</p>
            </div>
            <a
                class="admin-store-management__back-link"
                href="{{ route('admin.stores.index') }}"
            >
                店舗一覧へ戻る
            </a>
        </header>

        @include('admin.stores.partials.errors')

        <form
            class="admin-store-form"
            method="POST"
            action="{{ route('admin.stores.store') }}"
        >
            @csrf

            <label class="admin-store-form__field">
                <span>店舗名</span>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    maxlength="255"
                    required
                >
            </label>

            <label class="admin-store-form__field">
                <span>店舗コード</span>
                <input
                    type="text"
                    name="code"
                    value="{{ old('code') }}"
                    maxlength="100"
                    required
                >
                <small>作成後は変更できません。</small>
            </label>

            <label class="admin-store-form__field">
                <span>エリア</span>
                <input
                    type="text"
                    name="area"
                    value="{{ old('area') }}"
                    maxlength="100"
                    required
                >
            </label>

            <div class="admin-store-form__actions">
                <button type="submit">店舗を追加</button>
                <a href="{{ route('admin.stores.index') }}">キャンセル</a>
            </div>
        </form>
    </section>
@endsection
