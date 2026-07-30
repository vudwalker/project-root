@extends('layouts.admin')

@section('title', '店舗情報管理｜管理画面')

@section('content')
    <section class="admin-store-management" aria-labelledby="admin-store-index-title">
        <header class="admin-store-management__header">
            <div>
                <h1 id="admin-store-index-title">店舗情報管理</h1>
                <p>店舗を検索し、詳細設定を開きます。</p>
            </div>
            <div class="admin-store-management__header-actions">
                @if ($canCreateStore)
                    <a
                        class="admin-store-management__primary-link"
                        href="{{ route('admin.stores.create') }}"
                    >
                        店舗追加
                    </a>
                @endif
                <a class="admin-store-management__back-link" href="{{ route('admin.top') }}">
                    シフト画面へ戻る
                </a>
            </div>
        </header>

        @if (session('status'))
            <p class="admin-store-management__success" role="status">
                {{ session('status') }}
            </p>
        @endif

        <form class="admin-store-search" method="GET" action="{{ route('admin.stores.index') }}">
            <label>
                <span>担当シフト管理者</span>
                <select name="manager_id">
                    <option value="">すべて</option>
                    @foreach ($managerOptions as $manager)
                        <option
                            value="{{ $manager->id }}"
                            @selected((string) $filters['manager_id'] === (string) $manager->id)
                        >
                            {{ $manager->name }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>エリア</span>
                <select name="area">
                    <option value="">すべて</option>
                    @foreach ($areaOptions as $area)
                        <option
                            value="{{ $area ?? '__unset__' }}"
                            @selected($filters['area'] === ($area ?? '__unset__'))
                        >
                            {{ $area ?? '未設定' }}
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="admin-store-search__query">
                <span>店舗名・店舗コード</span>
                <input
                    type="search"
                    name="q"
                    value="{{ $filters['query'] }}"
                    maxlength="100"
                    placeholder="店舗名またはコード"
                >
            </label>

            <div class="admin-store-search__actions">
                <button type="submit">検索</button>
                <a href="{{ route('admin.stores.index') }}">条件を解除</a>
            </div>
        </form>

        <div class="admin-store-table-scroll">
            <table class="admin-store-table">
                <caption class="admin-visually-hidden">
                    管理可能な店舗の検索結果
                </caption>
                <thead>
                    <tr>
                        <th scope="col">店舗名</th>
                        <th scope="col">店舗コード</th>
                        <th scope="col">エリア</th>
                        <th scope="col">担当シフト管理者</th>
                        <th scope="col">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($stores as $store)
                        <tr data-store-code="{{ $store->code }}">
                            <th scope="row">{{ $store->name }}</th>
                            <td>{{ $store->code }}</td>
                            <td>{{ $store->area ?? '未設定' }}</td>
                            <td>
                                {{ $store->shiftManagers->pluck('name')->join('、') ?: '未設定' }}
                            </td>
                            <td>
                                <a
                                    class="admin-store-table__edit-link"
                                    href="{{ route('admin.stores.edit', [
                                        'store' => $store->code,
                                    ]) }}"
                                >
                                    編集
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="admin-store-table__empty" colspan="5">
                                条件に一致する店舗はありません。
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
