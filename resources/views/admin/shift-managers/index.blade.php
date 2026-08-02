@extends('layouts.admin')

@section('title', 'シフト管理者管理｜管理画面')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-staff-management.css') }}?v={{ filemtime(public_path('css/admin-staff-management.css')) }}"
    >
@endpush

@section('content')
    <section class="admin-staff-page" aria-labelledby="admin-shift-manager-title">
        <header class="admin-staff-heading">
            <div>
                <p class="admin-staff-heading__eyebrow">組織内シフト管理者</p>
                <h1 id="admin-shift-manager-title">シフト管理者管理</h1>
            </div>
            <div class="admin-staff-heading__actions">
                <a class="admin-staff-button" href="{{ route('admin.top') }}">
                    シフト画面に戻る
                </a>
                <a class="admin-staff-button" href="{{ route('admin.staff.index') }}">
                    スタッフ管理
                </a>
                <a class="admin-staff-button admin-staff-button--primary" href="{{ route('admin.shift-managers.create') }}">
                    専任管理者追加
                </a>
            </div>
        </header>

        <p class="admin-staff-form__help">
            シフト管理者の担当店舗を管理します。スタッフ兼任者のスタッフロールは、スタッフ管理の編集画面から変更できます。
        </p>

        @if (session('status'))
            <p class="admin-staff-flash" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <section class="admin-staff-validation" role="alert">
                <h2>入力内容を確認してください</h2>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <form class="admin-staff-filters" method="GET" action="{{ route('admin.shift-managers.index') }}">
            <label class="admin-staff-field admin-staff-field--search">
                <span>氏名・メールアドレス</span>
                <input
                    type="search"
                    name="q"
                    value="{{ $filters['query'] }}"
                    maxlength="100"
                    placeholder="氏名またはメール"
                >
            </label>
            <label class="admin-staff-field">
                <span>在籍状態</span>
                <select name="status">
                    <option value="">すべて</option>
                    @foreach ($statusLabels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['status'] === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="admin-staff-field">
                <span>担当店舗</span>
                <select name="store_id">
                    <option value="">すべて</option>
                    @foreach ($stores as $store)
                        <option
                            value="{{ $store->id }}"
                            @selected($filters['store_id'] === (int) $store->id)
                        >
                            {{ $store->code }} {{ $store->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="admin-staff-filters__actions">
                <button class="admin-staff-button admin-staff-button--primary" type="submit">
                    検索
                </button>
                <a class="admin-staff-button" href="{{ route('admin.shift-managers.index') }}">クリア</a>
            </div>
        </form>

        @error('q')
            <p class="admin-staff-error" role="alert">{{ $message }}</p>
        @enderror

        <div class="admin-staff-table-scroll">
            <table class="admin-staff-table">
                <thead>
                    <tr>
                        <th scope="col">氏名</th>
                        <th scope="col">メールアドレス</th>
                        <th scope="col">在籍状態</th>
                        <th scope="col">ロール</th>
                        <th scope="col">担当店舗</th>
                        <th scope="col">編集</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($managers as $manager)
                        <tr>
                            <th scope="row">{{ $manager->name }}</th>
                            <td>{{ $manager->email }}</td>
                            <td>
                                <span class="admin-staff-status admin-staff-status--{{ $manager->status }}">
                                    {{ $statusLabels[$manager->status] ?? $manager->status }}
                                </span>
                            </td>
                            <td>
                                <span class="admin-staff-inline-list">
                                    @foreach ($manager->roles->sortBy('id') as $role)
                                        @if (isset($roleLabels[$role->code]))
                                            <span>{{ $roleLabels[$role->code] }}</span>
                                        @endif
                                    @endforeach
                                </span>
                            </td>
                            <td>
                                @if ($manager->managedStores->isEmpty())
                                    <span class="admin-staff-muted">なし</span>
                                @else
                                    <span class="admin-staff-inline-list">
                                        @foreach ($manager->managedStores as $store)
                                            <span>{{ $store->name }}</span>
                                        @endforeach
                                    </span>
                                @endif
                            </td>
                            <td class="admin-staff-table__action">
                                @if ($canEditManagers[(int) $manager->id] ?? false)
                                    <a
                                        class="admin-staff-button"
                                        href="{{ route('admin.shift-managers.edit', ['user' => $manager->id]) }}"
                                    >
                                        編集
                                    </a>
                                @else
                                    <span class="admin-staff-muted">編集不可</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="admin-staff-table__empty" colspan="6">
                                条件に一致するシフト管理者はいません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
