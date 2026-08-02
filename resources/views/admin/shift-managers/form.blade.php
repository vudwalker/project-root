@extends('layouts.admin')

@section('title', $formTitle.'｜管理画面')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-staff-management.css') }}?v={{ filemtime(public_path('css/admin-staff-management.css')) }}"
    >
@endpush

@php
    $hasStaffRole = $manager?->hasRole('staff') ?? false;
    $oldStoreIds = array_map('intval', old('store_ids', $selectedStoreIds));
    $nameField = $isCreate ? 'name' : 'profile_name';
    $emailField = $isCreate ? 'email' : 'profile_email';
    $passwordField = $isCreate ? 'password' : 'profile_password';
    $passwordConfirmationField = $isCreate
        ? 'password_confirmation'
        : 'profile_password_confirmation';
@endphp

@section('content')
    <section class="admin-staff-page" aria-labelledby="admin-shift-manager-form-title">
        <header class="admin-staff-heading">
            <div>
                <p class="admin-staff-heading__eyebrow">組織内シフト管理者</p>
                <h1 id="admin-shift-manager-form-title">{{ $formTitle }}</h1>
            </div>
            <a class="admin-staff-button" href="{{ route('admin.shift-managers.index') }}">
                一覧へ戻る
            </a>
        </header>

        @if (session('status'))
            <p class="admin-staff-flash" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <section class="admin-staff-validation" role="alert" aria-labelledby="shift-manager-errors-title">
                <h2 id="shift-manager-errors-title">入力内容を確認してください</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form
            method="POST"
            action="{{ $formAction }}"
            class="admin-staff-form"
            data-admin-staff-form
            novalidate
        >
            @csrf
            @if ($formMethod !== 'POST')
                @method($formMethod)
            @endif

            <section class="admin-staff-form__section" aria-labelledby="shift-manager-basic-title">
                <h2 id="shift-manager-basic-title">基本情報</h2>
                <div class="admin-staff-form__fields">
                    <label class="admin-staff-field">
                        <span>氏名</span>
                        <input
                            type="text"
                            name="{{ $nameField }}"
                            value="{{ old($nameField, $manager?->name) }}"
                            maxlength="255"
                            required
                            @class(['is-invalid' => $errors->has('name')])
                        >
                        @error('name')
                            <span class="admin-staff-field__error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="admin-staff-field">
                        <span>メールアドレス</span>
                        <input
                            type="email"
                            name="{{ $emailField }}"
                            value="{{ old($emailField, $manager?->email) }}"
                            maxlength="255"
                            autocomplete="email"
                            required
                            @class(['is-invalid' => $errors->has('email')])
                        >
                        @error('email')
                            <span class="admin-staff-field__error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="admin-staff-field">
                        <span>在籍状態</span>
                        <select name="status" required @class(['is-invalid' => $errors->has('status')])>
                            @foreach (($isCreate ? ['active' => $statusLabels['active']] : $statusLabels) as $value => $label)
                                <option
                                    value="{{ $value }}"
                                    @selected(old('status', $manager?->status ?? 'active') === $value)
                                >
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        @error('status')
                            <span class="admin-staff-field__error">{{ $message }}</span>
                        @enderror
                    </label>
                </div>
            </section>

            <section class="admin-staff-form__section" aria-labelledby="shift-manager-role-title">
                <h2 id="shift-manager-role-title">ロール</h2>
                <div class="admin-staff-role-list">
                    @if ($isCreate)
                        <label>
                            <input type="checkbox" checked disabled>
                            <span>シフト管理者（専任）</span>
                        </label>
                    @else
                        <span class="admin-staff-role-list__readonly">シフト管理者（必須）</span>
                        @if ($hasStaffRole)
                            <span class="admin-staff-role-list__readonly">スタッフ兼任</span>
                        @else
                            <span class="admin-staff-role-list__readonly">専任</span>
                        @endif
                        <p class="admin-staff-form__help">
                            スタッフロールの付与・解除はスタッフ管理の編集画面で行います。
                        </p>
                    @endif
                </div>
            </section>

            <section class="admin-staff-form__section" aria-labelledby="shift-manager-store-title">
                <h2 id="shift-manager-store-title">担当店舗</h2>
                <p class="admin-staff-form__help">
                    スタッフの勤務可能店舗とは別に、シフト管理者として管理する店舗を指定します。
                </p>
                <input type="hidden" name="store_ids[]" value="">
                <div class="admin-staff-store-table-scroll">
                    <table class="admin-staff-store-table">
                        <thead>
                            <tr>
                                <th scope="col">選択</th>
                                <th scope="col">店舗コード</th>
                                <th scope="col">店舗名</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($storeOptions as $storeOption)
                                <tr>
                                    <td>
                                        <input
                                            type="checkbox"
                                            name="store_ids[]"
                                            value="{{ $storeOption->id }}"
                                            @checked(in_array((int) $storeOption->id, $oldStoreIds, true))
                                            aria-label="{{ $storeOption->name }}を担当店舗にする"
                                        >
                                    </td>
                                    <td>{{ $storeOption->code }}</td>
                                    <td>{{ $storeOption->name }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3">登録済み店舗がありません。</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @error('store_ids')
                    <p class="admin-staff-field__error">{{ $message }}</p>
                @enderror
                @error('store_ids.*')
                    <p class="admin-staff-field__error">{{ $message }}</p>
                @enderror
            </section>

            <section class="admin-staff-form__section" aria-labelledby="shift-manager-password-title">
                <h2 id="shift-manager-password-title">パスワード</h2>
                @unless ($isCreate)
                    <p class="admin-staff-form__help">変更しない場合は空欄のままにしてください。</p>
                @endunless
                <div class="admin-staff-form__fields">
                    <label class="admin-staff-field">
                        <span>{{ $isCreate ? '初期パスワード' : '新しいパスワード' }}</span>
                        <input
                            type="password"
                            name="{{ $passwordField }}"
                            minlength="8"
                            autocomplete="new-password"
                            @required($isCreate)
                            @class(['is-invalid' => $errors->has('password')])
                        >
                        @error('password')
                            <span class="admin-staff-field__error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="admin-staff-field">
                        <span>パスワード確認</span>
                        <input
                            type="password"
                            name="{{ $passwordConfirmationField }}"
                            minlength="8"
                            autocomplete="new-password"
                            @required($isCreate)
                        >
                    </label>
                </div>
            </section>

            <div class="admin-staff-save-bar">
                <p data-admin-staff-save-status aria-live="polite">変更はありません</p>
                <button
                    class="admin-staff-button admin-staff-button--primary"
                    type="submit"
                    data-admin-staff-save
                    disabled
                >
                    保存
                </button>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-staff-management.js') }}" defer></script>
@endpush
