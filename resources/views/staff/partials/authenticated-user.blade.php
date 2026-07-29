<div class="staff-header__user">
    <span>{{ $loginUser['name'] }}</span>
    <form method="POST" action="{{ route('logout') }}" class="staff-header__logout-form">
        @csrf
        <button type="submit" class="staff-header__logout">ログアウト</button>
    </form>
</div>
