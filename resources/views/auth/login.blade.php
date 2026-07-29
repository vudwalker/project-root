<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ログイン｜シフト管理</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
</head>
<body class="auth-page">
    <main class="auth-page__main">
        <section class="auth-login" aria-labelledby="login-title">
            <h1 id="login-title" class="auth-login__title">シフト管理 ログイン</h1>

            <form method="POST" action="{{ route('login.store') }}" class="auth-login__form">
                @csrf

                <label class="auth-login__field">
                    <span>メールアドレス</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="username"
                        inputmode="email"
                        required
                        autofocus
                    >
                </label>

                <label class="auth-login__field">
                    <span>パスワード</span>
                    <input
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                @if ($errors->any())
                    <div class="auth-login__error" role="alert">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <button type="submit" class="auth-login__submit">ログイン</button>
            </form>
        </section>
    </main>
</body>
</html>
