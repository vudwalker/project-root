<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'スタッフ用シフト')</title>
    <link rel="stylesheet" href="{{ asset('css/staff-shift.css') }}">
</head>
<body class="staff-page">
    <main class="staff-page__main">
        @yield('content')
    </main>

    <script src="{{ asset('js/staff-shift.js') }}" defer></script>
</body>
</html>
