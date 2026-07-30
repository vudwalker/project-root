@extends('layouts.admin')

@section('title', $store->name.' 店舗情報編集｜管理画面')

@section('content')
    <section class="admin-store-management" aria-labelledby="admin-store-edit-title">
        <header class="admin-store-management__header">
            <div>
                <h1 id="admin-store-edit-title">店舗情報編集</h1>
                <p>店舗コードと組織は変更できません。</p>
            </div>
            <a
                class="admin-store-management__back-link"
                href="{{ route('admin.stores.index', ['status' => $filterStatus]) }}"
            >
                店舗一覧へ戻る
            </a>
        </header>

        @if ($errors->any())
            <div class="admin-store-form__errors" role="alert">
                <p>入力内容を確認してください。</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            class="admin-store-form"
            method="POST"
            action="{{ route('admin.stores.update', ['store' => $store->code]) }}"
        >
            @csrf
            @method('PATCH')
            <input type="hidden" name="filter_status" value="{{ $filterStatus }}">

            <dl class="admin-store-form__read-only">
                <div>
                    <dt>店舗コード</dt>
                    <dd>{{ $store->code }}</dd>
                </div>
            </dl>

            <label class="admin-store-form__field">
                <span>店舗名</span>
                <input
                    type="text"
                    name="name"
                    value="{{ old('name', $store->name) }}"
                    maxlength="255"
                    required
                >
            </label>

            @if ($canChangeStatus)
                <label class="admin-store-form__field">
                    <span>状態</span>
                    <select name="status" required>
                        <option
                            value="active"
                            @selected(old('status', $store->status) === 'active')
                        >
                            有効
                        </option>
                        <option
                            value="inactive"
                            @selected(old('status', $store->status) === 'inactive')
                        >
                            無効
                        </option>
                    </select>
                </label>
            @else
                <dl class="admin-store-form__read-only">
                    <div>
                        <dt>状態</dt>
                        <dd>{{ $store->status === 'active' ? '有効' : '無効' }}</dd>
                    </div>
                </dl>
            @endif

            <label class="admin-store-form__field">
                <span>表示順</span>
                <input
                    type="number"
                    name="display_order"
                    value="{{ old('display_order', $store->display_order) }}"
                    min="0"
                    step="1"
                    required
                >
            </label>

            <label class="admin-store-form__field">
                <span>人数チェック方式</span>
                <select name="staffing_check_mode" required>
                    @foreach ([
                        'disabled' => '無効',
                        'fixed_total' => '固定人数',
                        'pattern_combinations' => 'シフトパターンの組み合わせ',
                    ] as $value => $label)
                        <option
                            value="{{ $value }}"
                            @selected(
                                old('staffing_check_mode', $store->staffing_check_mode)
                                    === $value
                            )
                        >
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="admin-store-form__field">
                <span>固定必要人数</span>
                <input
                    type="number"
                    name="required_staff_count"
                    value="{{ old('required_staff_count', $store->required_staff_count) }}"
                    min="0"
                    step="1"
                >
                <small>
                    「固定人数」を選択した場合だけ必須です。現行の警告判定では使用しません。
                </small>
            </label>

            <p class="admin-store-form__notice">
                人数チェック方式を変更しても、既存の下書きシフトと公開版は変更されません。
            </p>

            <div class="admin-store-form__actions">
                <button type="submit">更新</button>
                <a href="{{ route('admin.stores.index', ['status' => $filterStatus]) }}">
                    キャンセル
                </a>
            </div>
        </form>
    </section>
@endsection
