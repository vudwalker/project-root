<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '管理者用シフト')</title>
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-shift.css') }}?v={{ filemtime(public_path('css/admin-shift.css')) }}"
    >
</head>
<body class="admin-shift-page">
    <aside class="admin-utility" aria-label="管理者情報">
        <span class="admin-utility__context">管理画面</span>
        <span>{{ $loginUserName }}</span>
        <span class="admin-utility__logout" aria-disabled="true">ログアウト</span>
    </aside>

    <main class="admin-shift-page__main">
        @yield('content')
    </main>

    <script src="{{ asset('js/admin-shift-static.js') }}" defer></script>
</body>
</html>
