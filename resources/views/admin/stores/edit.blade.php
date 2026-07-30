@extends('layouts.admin')

@section('title', $store->name.' 店舗詳細｜管理画面')

@section('content')
    <section class="admin-store-management" aria-labelledby="admin-store-edit-title">
        <header class="admin-store-management__header">
            <div>
                <h1 id="admin-store-edit-title">{{ $store->name }} 店舗詳細</h1>
                <p>
                    店舗コード：{{ $store->code }} ／
                    エリア：{{ $store->area ?? '未設定' }}
                </p>
            </div>
            <a
                class="admin-store-management__back-link"
                href="{{ route('admin.stores.index') }}"
            >
                店舗一覧へ戻る
            </a>
        </header>

        @if (session('status'))
            <p class="admin-store-management__success" role="status">
                {{ session('status') }}
            </p>
        @endif

        @include('admin.stores.partials.errors')

        <nav class="admin-store-detail-nav" aria-label="店舗詳細の区画">
            <a href="#basic-information">基本情報</a>
            <a href="#staff-members">所属スタッフ</a>
            <a href="#shift-managers">担当シフト管理者</a>
            <a href="#shift-patterns">使用シフトパターン</a>
            <a href="#staffing-settings">人数配置判定</a>
        </nav>

        <section id="basic-information" class="admin-store-detail-section">
            <h2>基本情報</h2>
            <form
                class="admin-store-form admin-store-form--section"
                method="POST"
                action="{{ route('admin.stores.update', ['store' => $store->code]) }}"
            >
                @csrf
                @method('PATCH')

                <label class="admin-store-form__field">
                    <span>店舗名</span>
                    <input
                        type="text"
                        name="name"
                        value="{{ old('name', $store->name) }}"
                        maxlength="255"
                        required
                    >
                </label>

                <dl class="admin-store-form__read-only">
                    <div>
                        <dt>店舗コード</dt>
                        <dd>{{ $store->code }}</dd>
                    </div>
                </dl>

                <label class="admin-store-form__field">
                    <span>エリア</span>
                    <input
                        type="text"
                        name="area"
                        value="{{ old('area', $store->area) }}"
                        maxlength="100"
                        placeholder="未設定"
                    >
                </label>

                @if ($canChangeStatus)
                    <label class="admin-store-form__field">
                        <span>有効・無効</span>
                        <select name="status" required>
                            <option
                                value="active"
                                @selected(old('status', $store->status) === 'active')
                            >
                                有効
                            </option>
                            <option
                                value="inactive"
                                @selected(old('status', $store->status) === 'inactive')
                            >
                                無効
                            </option>
                        </select>
                    </label>
                @else
                    <dl class="admin-store-form__read-only">
                        <div>
                            <dt>有効・無効</dt>
                            <dd>{{ $store->status === 'active' ? '有効' : '無効' }}</dd>
                        </div>
                    </dl>
                @endif

                <p class="admin-store-form__notice">
                    店舗コードは作成後に変更できません。無効にしても既存シフトと公開版は削除されません。
                </p>

                <div class="admin-store-form__actions">
                    <button type="submit">基本情報を更新</button>
                </div>
            </form>
        </section>

        <section id="staff-members" class="admin-store-detail-section">
            <div class="admin-store-detail-section__heading">
                <h2>所属スタッフ</h2>
                <a
                    class="admin-flat-button admin-store-staff-add-link"
                    href="{{ route('admin.stores.edit', [
                        'store' => $store->code,
                        'staff_add' => 1,
                    ]) }}#staff-members"
                    aria-expanded="{{ $staffAddOpen ? 'true' : 'false' }}"
                >
                    スタッフを追加
                </a>
            </div>
            <p>現在この店舗に所属しているスタッフを表示します。</p>

            <div class="admin-store-member-table-scroll">
                <table class="admin-store-member-table" data-store-member-table>
                    <caption class="admin-visually-hidden">
                        {{ $store->name }}の所属スタッフ
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">氏名</th>
                            <th scope="col">メールアドレス</th>
                            <th scope="col">主所属</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($store->staffMembers as $staff)
                            @php
                                $isPrimary = (int) $staff->primary_store_id
                                    === (int) $store->id;
                            @endphp
                            <tr data-store-member-user-id="{{ $staff->id }}">
                                <th scope="row">{{ $staff->name }}</th>
                                <td>{{ $staff->email }}</td>
                                <td>
                                    @if ($isPrimary)
                                        <span class="admin-store-primary-label">主所属</span>
                                    @else
                                        <span aria-label="主所属ではありません">―</span>
                                    @endif
                                </td>
                                <td class="admin-store-member-table__action">
                                    @if ($isPrimary)
                                        <button
                                            class="admin-flat-button"
                                            type="button"
                                            disabled
                                        >
                                            解除不可
                                        </button>
                                        <small>主所属変更後に解除できます</small>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route(
                                                'admin.stores.staff.destroy',
                                                [
                                                    'store' => $store->code,
                                                    'staff' => $staff->id,
                                                ]
                                            ) }}"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button class="admin-flat-button" type="submit">
                                                所属解除
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="admin-store-member-table__empty" colspan="4">
                                    現在所属しているスタッフはいません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <p class="admin-store-form__notice">
                所属解除後も既存の下書き・公開シフトは保持されます。
            </p>

            @if ($staffAddOpen)
                <section class="admin-store-staff-search" aria-labelledby="staff-search-title">
                    <div class="admin-store-staff-search__heading">
                        <h3 id="staff-search-title">未所属スタッフを検索</h3>
                        <a
                            href="{{ route('admin.stores.edit', [
                                'store' => $store->code,
                            ]) }}#staff-members"
                        >
                            閉じる
                        </a>
                    </div>

                    <form
                        class="admin-store-staff-search__form"
                        method="GET"
                        action="{{ route('admin.stores.edit', [
                            'store' => $store->code,
                        ]) }}"
                    >
                        <input type="hidden" name="staff_add" value="1">
                        <label>
                            <span>氏名・メールアドレス</span>
                            <input
                                type="search"
                                name="staff_query"
                                value="{{ $staffQuery }}"
                                maxlength="100"
                                required
                            >
                        </label>
                        <button class="admin-flat-button" type="submit">検索</button>
                    </form>

                    @if ($staffQuery !== '')
                        <div class="admin-store-member-table-scroll">
                            <table
                                class="admin-store-member-table admin-store-member-table--search"
                                data-store-staff-search-results
                            >
                                <caption class="admin-visually-hidden">
                                    未所属スタッフの検索結果
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">氏名</th>
                                        <th scope="col">メールアドレス</th>
                                        <th scope="col">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($staffSearchResults as $staff)
                                        <tr data-staff-search-user-id="{{ $staff->id }}">
                                            <th scope="row">{{ $staff->name }}</th>
                                            <td>{{ $staff->email }}</td>
                                            <td class="admin-store-member-table__action">
                                                <form
                                                    method="POST"
                                                    action="{{ route(
                                                        'admin.stores.staff.store',
                                                        ['store' => $store->code]
                                                    ) }}"
                                                >
                                                    @csrf
                                                    <input
                                                        type="hidden"
                                                        name="staff_user_id"
                                                        value="{{ $staff->id }}"
                                                    >
                                                    <button
                                                        class="admin-flat-button"
                                                        type="submit"
                                                    >
                                                        追加
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td
                                                class="admin-store-member-table__empty"
                                                colspan="3"
                                            >
                                                条件に一致する未所属スタッフはいません。
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="admin-store-staff-search__guide">
                            氏名またはメールアドレスを入力して検索してください。
                        </p>
                    @endif
                </section>
            @endif
        </section>

        <section id="shift-managers" class="admin-store-detail-section">
            <h2>担当シフト管理者</h2>
            <p>1店舗へ複数のシフト管理者を割り当てられます。</p>

            @if ($canManageManagers)
                <form
                    class="admin-store-form admin-store-form--wide"
                    method="POST"
                    action="{{ route('admin.stores.managers.update', [
                        'store' => $store->code,
                    ]) }}"
                >
                    @csrf
                    @method('PATCH')

                    @php
                        $selectedManagerIds = collect(
                            old('manager_user_ids', $activeManagerIds->all())
                        )->map(fn ($id) => (int) $id);
                    @endphp

                    <div class="admin-store-choice-grid">
                        @forelse ($managerCandidates as $manager)
                            <label class="admin-store-choice">
                                <input
                                    type="checkbox"
                                    name="manager_user_ids[]"
                                    value="{{ $manager->id }}"
                                    @checked($selectedManagerIds->contains((int) $manager->id))
                                >
                                <span>
                                    <strong>{{ $manager->name }}</strong>
                                    <small>{{ $manager->email }}</small>
                                </span>
                            </label>
                        @empty
                            <p>割り当て可能なシフト管理者はいません。</p>
                        @endforelse
                    </div>

                    <div class="admin-store-form__actions">
                        <button type="submit">担当管理者を更新</button>
                    </div>
                </form>
            @else
                @php
                    $readOnlyManagers = $managerCandidates->whereIn(
                        'id',
                        $activeManagerIds
                    );
                @endphp
                <div class="admin-store-read-only-list">
                    @forelse ($readOnlyManagers as $manager)
                        <span>{{ $manager->name }}</span>
                    @empty
                        <span>未設定</span>
                    @endforelse
                </div>
                <p class="admin-store-form__notice">
                    担当管理者を変更できるのはシステム管理者だけです。
                </p>
            @endif
        </section>

        <section id="shift-patterns" class="admin-store-detail-section">
            <h2>使用シフトパターン</h2>
            <p>勤務時間数は既存値を維持し、開始・終了時刻から自動計算しません。</p>
            <form
                class="admin-store-form admin-store-form--table"
                method="POST"
                action="{{ route('admin.stores.patterns.update', [
                    'store' => $store->code,
                ]) }}"
            >
                @csrf
                @method('PATCH')

                <div class="admin-store-settings-scroll">
                    <table class="admin-store-settings-table">
                        <thead>
                            <tr>
                                <th scope="col">コード</th>
                                <th scope="col">開始時刻</th>
                                <th scope="col">終了時刻</th>
                                <th scope="col">翌日終了</th>
                                <th scope="col">表示順</th>
                                <th scope="col">使用</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($store->shiftPatterns as $index => $pattern)
                                <tr>
                                    <td>
                                        <input
                                            type="hidden"
                                            name="patterns[{{ $index }}][id]"
                                            value="{{ $pattern->id }}"
                                        >
                                        <input
                                            type="text"
                                            name="patterns[{{ $index }}][code]"
                                            value="{{ old("patterns.$index.code", $pattern->code) }}"
                                            maxlength="20"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="time"
                                            name="patterns[{{ $index }}][start_time]"
                                            value="{{ old(
                                                "patterns.$index.start_time",
                                                $pattern->start_time
                                                    ? substr((string) $pattern->start_time, 0, 5)
                                                    : null
                                            ) }}"
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="time"
                                            name="patterns[{{ $index }}][end_time]"
                                            value="{{ old(
                                                "patterns.$index.end_time",
                                                $pattern->end_time
                                                    ? substr((string) $pattern->end_time, 0, 5)
                                                    : null
                                            ) }}"
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="hidden"
                                            name="patterns[{{ $index }}][ends_next_day]"
                                            value="0"
                                        >
                                        <input
                                            type="checkbox"
                                            name="patterns[{{ $index }}][ends_next_day]"
                                            value="1"
                                            @checked((bool) old(
                                                "patterns.$index.ends_next_day",
                                                $pattern->end_day_offset === 1
                                            ))
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            name="patterns[{{ $index }}][display_order]"
                                            value="{{ old(
                                                "patterns.$index.display_order",
                                                $pattern->display_order
                                            ) }}"
                                            min="0"
                                            step="1"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="hidden"
                                            name="patterns[{{ $index }}][is_active]"
                                            value="0"
                                        >
                                        <input
                                            type="checkbox"
                                            name="patterns[{{ $index }}][is_active]"
                                            value="1"
                                            @checked((bool) old(
                                                "patterns.$index.is_active",
                                                $pattern->is_active
                                            ))
                                        >
                                    </td>
                                </tr>
                            @endforeach

                            @php
                                $newPatternIndex = $store->shiftPatterns->count();
                            @endphp
                            <tr class="admin-store-settings-table__new-row">
                                <td>
                                    <input
                                        type="text"
                                        name="patterns[{{ $newPatternIndex }}][code]"
                                        value="{{ old("patterns.$newPatternIndex.code") }}"
                                        maxlength="20"
                                        placeholder="新規コード"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="time"
                                        name="patterns[{{ $newPatternIndex }}][start_time]"
                                        value="{{ old("patterns.$newPatternIndex.start_time") }}"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="time"
                                        name="patterns[{{ $newPatternIndex }}][end_time]"
                                        value="{{ old("patterns.$newPatternIndex.end_time") }}"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="hidden"
                                        name="patterns[{{ $newPatternIndex }}][ends_next_day]"
                                        value="0"
                                    >
                                    <input
                                        type="checkbox"
                                        name="patterns[{{ $newPatternIndex }}][ends_next_day]"
                                        value="1"
                                        @checked((bool) old(
                                            "patterns.$newPatternIndex.ends_next_day",
                                            false
                                        ))
                                    >
                                </td>
                                <td>
                                    <input
                                        type="number"
                                        name="patterns[{{ $newPatternIndex }}][display_order]"
                                        value="{{ old("patterns.$newPatternIndex.display_order", 0) }}"
                                        min="0"
                                        step="1"
                                    >
                                </td>
                                <td>
                                    <input
                                        type="hidden"
                                        name="patterns[{{ $newPatternIndex }}][is_active]"
                                        value="0"
                                    >
                                    <input
                                        type="checkbox"
                                        name="patterns[{{ $newPatternIndex }}][is_active]"
                                        value="1"
                                        @checked((bool) old(
                                            "patterns.$newPatternIndex.is_active",
                                            false
                                        ))
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="admin-store-form__actions">
                    <button type="submit">シフトパターンを更新</button>
                </div>
            </form>
        </section>

        <section id="staffing-settings" class="admin-store-detail-section">
            <h2>人数配置判定</h2>
            <form
                class="admin-store-form admin-store-form--table"
                method="POST"
                action="{{ route('admin.stores.staffing.update', [
                    'store' => $store->code,
                ]) }}"
                data-staffing-settings
            >
                @csrf
                @method('PATCH')

                <label class="admin-store-form__field">
                    <span>判定方式</span>
                    <select name="staffing_check_mode" data-staffing-mode required>
                        @foreach ([
                            'disabled' => 'チェックなし',
                            'fixed_total' => '固定人数チェック',
                            'pattern_combinations' => '勤務パターン組合せチェック',
                        ] as $value => $label)
                            <option
                                value="{{ $value }}"
                                @selected(
                                    old('staffing_check_mode', $store->staffing_check_mode)
                                        === $value
                                )
                            >
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label
                    class="admin-store-form__field"
                    data-fixed-staffing
                    @if (old('staffing_check_mode', $store->staffing_check_mode) !== 'fixed_total')
                        hidden
                    @endif
                >
                    <span>固定必要人数</span>
                    <input
                        type="number"
                        name="required_staff_count"
                        value="{{ old(
                            'required_staff_count',
                            $store->required_staff_count
                        ) }}"
                        min="0"
                        step="1"
                    >
                </label>

                <div
                    class="admin-store-pattern-staffing"
                    data-pattern-staffing
                    @if (
                        old('staffing_check_mode', $store->staffing_check_mode)
                            !== 'pattern_combinations'
                    )
                        hidden
                    @endif
                >
                    <h3>全日共通の勤務パターン組合せ</h3>
                    <p>
                        各行が選択肢（OR条件）、同じ行内の複数パターンがAND条件です。
                        空欄のパターンは条件に含めません。
                    </p>

                    @php
                        $staffingOptions = $staffingRequirement?->options ?? collect();
                    @endphp

                    <div class="admin-store-settings-scroll">
                        <table class="admin-store-settings-table admin-store-staffing-table">
                            <thead>
                                <tr>
                                    <th scope="col">選択肢コード</th>
                                    <th scope="col">表示順</th>
                                    @foreach ($store->shiftPatterns as $pattern)
                                        <th scope="col">{{ $pattern->code }} 必要数</th>
                                    @endforeach
                                    <th scope="col">削除</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($staffingOptions as $optionIndex => $option)
                                    <tr>
                                        <td>
                                            <input
                                                type="hidden"
                                                name="staffing_options[{{ $optionIndex }}][id]"
                                                value="{{ $option->id }}"
                                            >
                                            <input
                                                type="text"
                                                name="staffing_options[{{ $optionIndex }}][code]"
                                                value="{{ old(
                                                    "staffing_options.$optionIndex.code",
                                                    $option->code
                                                ) }}"
                                                maxlength="50"
                                                required
                                            >
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                name="staffing_options[{{ $optionIndex }}][display_order]"
                                                value="{{ old(
                                                    "staffing_options.$optionIndex.display_order",
                                                    $option->display_order
                                                ) }}"
                                                min="0"
                                                step="1"
                                                required
                                            >
                                        </td>
                                        @foreach ($store->shiftPatterns as $pattern)
                                            @php
                                                $optionPattern = $option->patterns
                                                    ->firstWhere(
                                                        'store_shift_pattern_id',
                                                        $pattern->id
                                                    );
                                            @endphp
                                            <td>
                                                <input
                                                    type="number"
                                                    name="staffing_options[{{ $optionIndex }}][pattern_counts][{{ $pattern->id }}]"
                                                    value="{{ old(
                                                        "staffing_options.$optionIndex.pattern_counts.$pattern->id",
                                                        $optionPattern?->required_count
                                                    ) }}"
                                                    min="0"
                                                    step="1"
                                                >
                                            </td>
                                        @endforeach
                                        <td>
                                            <input
                                                type="hidden"
                                                name="staffing_options[{{ $optionIndex }}][remove]"
                                                value="0"
                                            >
                                            <input
                                                type="checkbox"
                                                name="staffing_options[{{ $optionIndex }}][remove]"
                                                value="1"
                                                @checked((bool) old(
                                                    "staffing_options.$optionIndex.remove",
                                                    false
                                                ))
                                            >
                                        </td>
                                    </tr>
                                @endforeach

                                @php
                                    $newOptionIndex = $staffingOptions->count();
                                @endphp
                                <tr class="admin-store-settings-table__new-row">
                                    <td>
                                        <input
                                            type="text"
                                            name="staffing_options[{{ $newOptionIndex }}][code]"
                                            value="{{ old(
                                                "staffing_options.$newOptionIndex.code"
                                            ) }}"
                                            maxlength="50"
                                            placeholder="新規選択肢"
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            name="staffing_options[{{ $newOptionIndex }}][display_order]"
                                            value="{{ old(
                                                "staffing_options.$newOptionIndex.display_order",
                                                0
                                            ) }}"
                                            min="0"
                                            step="1"
                                        >
                                    </td>
                                    @foreach ($store->shiftPatterns as $pattern)
                                        <td>
                                            <input
                                                type="number"
                                                name="staffing_options[{{ $newOptionIndex }}][pattern_counts][{{ $pattern->id }}]"
                                                value="{{ old(
                                                    "staffing_options.$newOptionIndex.pattern_counts.$pattern->id"
                                                ) }}"
                                                min="0"
                                                step="1"
                                            >
                                        </td>
                                    @endforeach
                                    <td>
                                        <input
                                            type="hidden"
                                            name="staffing_options[{{ $newOptionIndex }}][remove]"
                                            value="0"
                                        >
                                        —
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="admin-store-form__notice">
                    設定変更は既存の下書きシフトと公開版を直接変更しません。
                </p>

                <div class="admin-store-form__actions">
                    <button type="submit">人数配置判定を更新</button>
                </div>
            </form>
        </section>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-store-management.js') }}" defer></script>
@endpush
