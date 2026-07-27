<!DOCTYPE html>
<html lang="ja">
<head>
    {{-- すべてのスタッフ画面で共通して使うHTMLの基本設定です。 --}}
    <meta charset="UTF-8">
    {{-- スマートフォンでPC幅に縮小されず、端末の横幅に合わせて表示します。 --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- 各画面でtitleが指定されていない場合は、後ろの初期値を使います。 --}}
    <title>@yield('title', 'スタッフ用シフト')</title>
    {{--
        見た目を調整するときは public/css/staff-shift.css を編集します。
        更新日時をURLへ付けることで、CSS変更後に古いブラウザキャッシュが使われるのを防ぎます。
    --}}
    <link
        rel="stylesheet"
        href="{{ asset('css/staff-shift.css') }}?v={{ filemtime(public_path('css/staff-shift.css')) }}"
    >
</head>
<body class="staff-page">
    {{-- 各画面の @section('content') がここに入ります。 --}}
    <main class="staff-page__main">
        @yield('content')
    </main>

    {{-- deferにより、HTMLを読み終えてからJavaScriptを実行します。 --}}
    <script src="{{ asset('js/staff-shift.js') }}" defer></script>
</body>
</html>
