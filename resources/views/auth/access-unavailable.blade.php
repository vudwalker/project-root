<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>利用可能な画面がありません｜シフト管理</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-page">
    <main class="auth-page__main">
        <section class="auth-login" aria-labelledby="access-title">
            <h1 id="access-title" class="auth-login__title">利用可能な画面がありません</h1>
            <p class="auth-login__message">
                アカウントの権限を管理者へ確認してください。
            </p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="auth-login__submit">ログアウト</button>
            </form>
        </section>
    </main>
</body>
</html>
