<!DOCTYPE html>
<html lang="ja">
@php
    $isSystemAdmin = auth()->user()?->hasRole('system_admin') === true;
    $adminRoleClass = $isSystemAdmin ? 'system-admin' : 'shift-manager';
    $adminRoleLabel = $isSystemAdmin ? 'システム管理者画面' : 'シフト管理者画面';
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '管理者用シフト')</title>
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-shift.css') }}?v={{ filemtime(public_path('css/admin-shift.css')) }}"
    >
    @stack('styles')
</head>
<body class="admin-shift-page admin-shift-page--{{ $adminRoleClass }}">
    <aside class="admin-utility" aria-label="管理者情報">
        <span class="admin-utility__context">{{ $adminRoleLabel }}</span>
        <span>{{ $loginUserName }}</span>
        <a class="admin-utility__link" href="{{ route('admin.stores.index') }}">
            店舗管理
        </a>
        <a class="admin-utility__link" href="{{ route('admin.staff.index') }}">
            スタッフ管理
        </a>
        <form method="POST" action="{{ route('logout') }}" class="admin-utility__logout-form">
            @csrf
            <button type="submit" class="admin-utility__logout">ログアウト</button>
        </form>
    </aside>

    <main class="admin-shift-page__main">
        @yield('content')
    </main>

    <script src="{{ asset('js/admin-shift-static.js') }}" defer></script>
    @stack('scripts')
</body>
</html>
