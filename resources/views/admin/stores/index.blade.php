@extends('layouts.admin')

@section('title', '店舗情報管理｜管理画面')

@section('content')
    <section class="admin-store-management" aria-labelledby="admin-store-index-title">
        <header class="admin-store-management__header">
            <div>
                <h1 id="admin-store-index-title">店舗情報管理</h1>
                <p>同一組織内で管理可能な店舗だけを表示しています。</p>
            </div>
            <a class="admin-store-management__back-link" href="{{ route('admin.top') }}">
                シフト画面へ戻る
            </a>
        </header>

        @if ($canFilterInactive)
            <nav class="admin-store-filter" aria-label="店舗状態の絞り込み">
                @foreach ([
                    'all' => 'すべて',
                    'active' => '有効',
                    'inactive' => '無効',
                ] as $value => $label)
                    <a
                        href="{{ route('admin.stores.index', ['status' => $value]) }}"
                        @class([
                            'admin-store-filter__link',
                            'is-current' => $statusFilter === $value,
                        ])
                        @if ($statusFilter === $value) aria-current="page" @endif
                    >
                        {{ $label }}
                    </a>
                @endforeach
            </nav>
        @else
            <p class="admin-store-filter__manager-note">
                現在有効な担当店舗だけを表示しています。
            </p>
        @endif

        @if (session('status'))
            <p class="admin-store-management__success" role="status">
                {{ session('status') }}
            </p>
        @endif

        <div class="admin-store-table-scroll">
            <table class="admin-store-table">
                <caption class="admin-visually-hidden">
                    管理可能な店舗の一覧
                </caption>
                <thead>
                    <tr>
                        <th scope="col">店舗名</th>
                        <th scope="col">店舗コード</th>
                        <th scope="col">状態</th>
                        <th scope="col">表示順</th>
                        <th scope="col">人数チェック方式</th>
                        <th scope="col">固定必要人数</th>
                        <th scope="col">担当シフト管理者</th>
                        <th scope="col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stores as $store)
                        <tr
                            data-store-code="{{ $store->code }}"
                            data-store-status="{{ $store->status }}"
                        >
                            <th scope="row">{{ $store->name }}</th>
                            <td>{{ $store->code }}</td>
                            <td>
                                <span @class([
                                    'admin-store-status',
                                    'is-active' => $store->status === 'active',
                                    'is-inactive' => $store->status === 'inactive',
                                ])>
                                    {{ $store->status === 'active' ? '有効' : '無効' }}
                                </span>
                            </td>
                            <td>{{ $store->display_order }}</td>
                            <td>{{ $store->staffing_check_mode }}</td>
                            <td>
                                {{ $store->required_staff_count === null
                                    ? '—'
                                    : $store->required_staff_count }}
                            </td>
                            <td>
                                {{ $store->shiftManagers->pluck('name')->join('、') ?: '未設定' }}
                            </td>
                            <td>
                                <a
                                    class="admin-store-table__edit-link"
                                    href="{{ route('admin.stores.edit', [
                                        'store' => $store->code,
                                        'status' => $statusFilter,
                                    ]) }}"
                                >
                                    編集
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="admin-store-table__empty" colspan="8">
                                条件に一致する店舗はありません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
