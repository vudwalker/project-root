@extends('layouts.admin')

@section('title', 'スタッフ管理｜管理画面')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-staff-management.css') }}?v={{ filemtime(public_path('css/admin-staff-management.css')) }}"
    >
@endpush

@section('content')
    <section class="admin-staff-page" aria-labelledby="admin-staff-title">
        <header class="admin-staff-heading">
            <div>
                <p class="admin-staff-heading__eyebrow">組織内スタッフ</p>
                <h1 id="admin-staff-title">スタッフ管理</h1>
            </div>
            <a class="admin-staff-button admin-staff-button--primary" href="{{ route('admin.staff.create') }}">
                スタッフ追加
            </a>
        </header>

        @if (session('status'))
            <p class="admin-staff-flash" role="status">{{ session('status') }}</p>
        @endif

        <form class="admin-staff-filters" method="GET" action="{{ route('admin.staff.index') }}">
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
                <span>勤務可能店舗</span>
                <select name="store_id">
                    <option value="">すべて</option>
                    @foreach ($storeOptions as $store)
                        <option
                            value="{{ $store->id }}"
                            @selected($filters['store_id'] === (int) $store->id)
                        >
                            {{ $store->code }} {{ $store->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="admin-staff-field">
                <span>ロール</span>
                <select name="role">
                    <option value="">すべて</option>
                    @foreach ($roleLabels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['role'] === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </label>
            <div class="admin-staff-filters__actions">
                <button class="admin-staff-button admin-staff-button--primary" type="submit">
                    検索
                </button>
                <a class="admin-staff-button" href="{{ route('admin.staff.index') }}">クリア</a>
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
                        <th scope="col">勤務可能店舗</th>
                        <th scope="col">編集</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($staffMembers as $staff)
                        <tr>
                            <th scope="row">{{ $staff->name }}</th>
                            <td>{{ $staff->email }}</td>
                            <td>
                                <span class="admin-staff-status admin-staff-status--{{ $staff->status }}">
                                    {{ $statusLabels[$staff->status] ?? $staff->status }}
                                </span>
                            </td>
                            <td>
                                <span class="admin-staff-inline-list">
                                    @foreach ($staff->roles->sortBy('id') as $role)
                                        <span>{{ $roleLabels[$role->code] ?? $role->name }}</span>
                                    @endforeach
                                </span>
                            </td>
                            <td>
                                @if ($staff->stores->isEmpty())
                                    <span class="admin-staff-muted">なし</span>
                                @else
                                    <span class="admin-staff-inline-list">
                                        @foreach ($staff->stores as $store)
                                            <span>{{ $store->name }}</span>
                                        @endforeach
                                    </span>
                                @endif
                            </td>
                            <td class="admin-staff-table__action">
                                @if ($canEditStaff[(int) $staff->id] ?? false)
                                    <a
                                        class="admin-staff-button"
                                        href="{{ route('admin.staff.edit', ['user' => $staff->id]) }}"
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
                                条件に一致するスタッフはいません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
