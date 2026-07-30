@if ($errors->any())
    <div class="admin-store-form__errors" role="alert">
        <p>入力内容を確認してください。</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
