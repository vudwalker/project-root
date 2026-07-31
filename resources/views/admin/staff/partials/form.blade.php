@php
    $hasStaffRole = $staff?->hasRole('staff') ?? true;
    $hasShiftManagerRole = $staff?->hasRole('shift_manager') ?? false;
    $hasSystemAdminRole = $staff?->hasRole('system_admin') ?? false;
    $oldStoreIds = array_map('intval', old('store_ids', $selectedStoreIds));
@endphp

<section class="admin-staff-page" aria-labelledby="admin-staff-form-title">
    <header class="admin-staff-heading">
        <div>
            <p class="admin-staff-heading__eyebrow">組織内スタッフ</p>
            <h1 id="admin-staff-form-title">{{ $formTitle }}</h1>
        </div>
        <a class="admin-staff-button" href="{{ route('admin.staff.index') }}">一覧へ戻る</a>
    </header>

    @if (session('status'))
        <p class="admin-staff-flash" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <section class="admin-staff-validation" role="alert" aria-labelledby="staff-errors-title">
            <h2 id="staff-errors-title">入力内容を確認してください</h2>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <form
        method="POST"
        action="{{ $formAction }}"
        class="admin-staff-form"
        data-admin-staff-form
        novalidate
    >
        @csrf
        @if ($formMethod !== 'POST')
            @method($formMethod)
        @endif

        <section class="admin-staff-form__section" aria-labelledby="staff-basic-title">
            <h2 id="staff-basic-title">基本情報</h2>
            <div class="admin-staff-form__fields">
                <label class="admin-staff-field">
                    <span>氏名</span>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name', $staff?->name) }}"
                        maxlength="255"
                        required
                        @class(['is-invalid' => $errors->has('name')])
                    >
                    @error('name')
                        <span class="admin-staff-field__error">{{ $message }}</span>
                    @enderror
                </label>
                <label class="admin-staff-field">
                    <span>メールアドレス</span>
                    <input
                        type="email"
                        name="email"
                        value="{{ old('email', $staff?->email) }}"
                        maxlength="255"
                        autocomplete="email"
                        required
                        @class(['is-invalid' => $errors->has('email')])
                    >
                    @error('email')
                        <span class="admin-staff-field__error">{{ $message }}</span>
                    @enderror
                </label>
                <label class="admin-staff-field">
                    <span>在籍状態</span>
                    <select name="status" required @class(['is-invalid' => $errors->has('status')])>
                        @foreach ($statusLabels as $value => $label)
                            <option
                                value="{{ $value }}"
                                @selected(old('status', $staff?->status ?? 'active') === $value)
                            >
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                    @error('status')
                        <span class="admin-staff-field__error">{{ $message }}</span>
                    @enderror
                </label>
            </div>
        </section>

        <section class="admin-staff-form__section" aria-labelledby="staff-role-title">
            <h2 id="staff-role-title">ロール</h2>
            <div class="admin-staff-role-list">
                @if ($isCreate)
                    <input type="hidden" name="staff_role" value="1">
                    <label>
                        <input type="checkbox" checked disabled>
                        <span>スタッフ（新規登録時は必須）</span>
                    </label>
                @elseif ($actor->hasRole('system_admin'))
                    <input type="hidden" name="staff_role" value="0">
                    <label>
                        <input
                            type="checkbox"
                            name="staff_role"
                            value="1"
                            @checked((bool) old('staff_role', $hasStaffRole))
                        >
                        <span>スタッフ</span>
                    </label>
                @elseif ($hasStaffRole)
                    <input type="hidden" name="staff_role" value="1">
                    <label>
                        <input type="checkbox" checked disabled>
                        <span>スタッフ（シフト管理者は解除できません）</span>
                    </label>
                @else
                    <input type="hidden" name="staff_role" value="0">
                    <label>
                        <input
                            type="checkbox"
                            name="staff_role"
                            value="1"
                            @checked((bool) old('staff_role', false))
                        >
                        <span>スタッフを付与</span>
                    </label>
                @endif

                @if ($canManageShiftManagerRole)
                    <input type="hidden" name="shift_manager_role" value="0">
                    <label>
                        <input
                            type="checkbox"
                            name="shift_manager_role"
                            value="1"
                            @checked((bool) old('shift_manager_role', $hasShiftManagerRole))
                        >
                        <span>シフト管理者</span>
                    </label>
                @elseif ($hasShiftManagerRole)
                    <span class="admin-staff-role-list__readonly">シフト管理者（変更不可）</span>
                @endif

                @if ($hasSystemAdminRole)
                    <span class="admin-staff-role-list__readonly">システム管理者（変更不可）</span>
                @endif
            </div>
            @error('staff_role')
                <p class="admin-staff-field__error">{{ $message }}</p>
            @enderror
            @error('shift_manager_role')
                <p class="admin-staff-field__error">{{ $message }}</p>
            @enderror
            @error('system_admin_role')
                <p class="admin-staff-field__error">{{ $message }}</p>
            @enderror
        </section>

        <section class="admin-staff-form__section" aria-labelledby="staff-store-title">
            <h2 id="staff-store-title">勤務可能店舗</h2>
            <p class="admin-staff-form__help">
                シフト管理者の管理対象店舗とは別の設定です。自動同期しません。
            </p>
            <input type="hidden" name="store_ids[]" value="">
            <div class="admin-staff-store-table-scroll">
                <table class="admin-staff-store-table">
                    <thead>
                        <tr>
                            <th scope="col">選択</th>
                            <th scope="col">店舗コード</th>
                            <th scope="col">店舗名</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($storeOptions as $storeOption)
                            <tr>
                                <td>
                                    <input
                                        type="checkbox"
                                        name="store_ids[]"
                                        value="{{ $storeOption->id }}"
                                        @checked(in_array((int) $storeOption->id, $oldStoreIds, true))
                                        aria-label="{{ $storeOption->name }}を勤務可能店舗にする"
                                    >
                                </td>
                                <td>{{ $storeOption->code }}</td>
                                <td>{{ $storeOption->name }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">登録済み店舗がありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('store_ids')
                <p class="admin-staff-field__error">{{ $message }}</p>
            @enderror
            @error('store_ids.*')
                <p class="admin-staff-field__error">{{ $message }}</p>
            @enderror
        </section>

        <section class="admin-staff-form__section" aria-labelledby="staff-password-title">
            <h2 id="staff-password-title">パスワード</h2>
            @unless ($isCreate)
                <p class="admin-staff-form__help">変更しない場合は空欄のままにしてください。</p>
            @endunless
            <div class="admin-staff-form__fields">
                <label class="admin-staff-field">
                    <span>{{ $isCreate ? '初期パスワード' : '新しいパスワード' }}</span>
                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                        @required($isCreate)
                        @class(['is-invalid' => $errors->has('password')])
                    >
                    @error('password')
                        <span class="admin-staff-field__error">{{ $message }}</span>
                    @enderror
                </label>
                <label class="admin-staff-field">
                    <span>パスワード確認</span>
                    <input
                        type="password"
                        name="password_confirmation"
                        minlength="8"
                        autocomplete="new-password"
                        @required($isCreate)
                    >
                </label>
            </div>
        </section>

        <div class="admin-staff-save-bar">
            <p data-admin-staff-save-status aria-live="polite">変更はありません</p>
            <button
                class="admin-staff-button admin-staff-button--primary"
                type="submit"
                data-admin-staff-save
                disabled
            >
                保存
            </button>
        </div>
    </form>
</section>
