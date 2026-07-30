@extends('layouts.admin')

@section('title', $store->name.' 店舗詳細｜管理画面')

@php
    $patternRows = old('patterns');

    if (! is_array($patternRows)) {
        $patternRows = $shiftPatterns->map(fn ($pattern) => [
            'id' => $pattern->id,
            'code' => $pattern->code,
            'start_time' => $pattern->start_time
                ? substr((string) $pattern->start_time, 0, 5)
                : null,
            'end_time' => $pattern->end_time
                ? substr((string) $pattern->end_time, 0, 5)
                : null,
            'work_hours' => \App\Support\WorkHours::format($pattern->work_hours),
        ])->all();
    }

    $staffingOptions = $staffingRequirement?->options ?? collect();
    $staffingRows = old('staffing_options');

    if (! is_array($staffingRows)) {
        $staffingRows = $staffingOptions->map(function ($option) use ($shiftPatterns) {
            $counts = [];

            foreach ($shiftPatterns as $pattern) {
                $optionPattern = $option->patterns->firstWhere(
                    'store_shift_pattern_id',
                    $pattern->id,
                );
                $counts[$pattern->id] = $optionPattern?->required_count;
            }

            return [
                'id' => $option->id,
                'code' => $option->code,
                'display_order' => $option->display_order,
                'pattern_counts' => $counts,
                'remove' => 0,
            ];
        })->all();

        $staffingRows[] = [
            'id' => null,
            'code' => '',
            'display_order' => $staffingOptions->count() + 1,
            'pattern_counts' => [],
            'remove' => 0,
        ];
    }

    $staffingMode = old('staffing_check_mode', $store->staffing_check_mode);
@endphp

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

        <form
            class="admin-store-detail-form"
            method="POST"
            action="{{ route('admin.stores.update', ['store' => $store->code]) }}"
            data-store-detail-form
            data-staff-candidates-url="{{ route('admin.stores.staff.candidates', ['store' => $store->code]) }}"
            @if ($canManageManagers)
                data-manager-candidates-url="{{ route('admin.stores.manager.candidates', ['store' => $store->code]) }}"
            @endif
        >
            @csrf
            @method('PATCH')

            <section id="basic-information" class="admin-store-detail-section">
                <h2>基本情報</h2>
                <div class="admin-store-basic-fields">
                    <label class="admin-store-form__field">
                        <span>店舗名</span>
                        <input
                            type="text"
                            name="name"
                            value="{{ old('name', $store->name) }}"
                            maxlength="255"
                            @error('name') aria-invalid="true" @enderror
                            required
                        >
                        @error('name')
                            <small class="admin-store-field-error">{{ $message }}</small>
                        @enderror
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
                            @error('area') aria-invalid="true" @enderror
                        >
                        @error('area')
                            <small class="admin-store-field-error">{{ $message }}</small>
                        @enderror
                    </label>
                </div>
                <p class="admin-store-form__notice">
                    店舗コードは作成後に変更できません。
                </p>
            </section>

            <section id="staff-members" class="admin-store-detail-section">
                <div class="admin-store-detail-section__heading">
                    <h2>所属スタッフ</h2>
                    <button
                        class="admin-flat-button"
                        type="button"
                        data-candidate-panel-toggle="staff"
                        aria-expanded="false"
                    >
                        スタッフを追加
                    </button>
                </div>
                <p>この店舗で勤務可能な、現在所属中のスタッフです。</p>

                @error('staff_user_ids')
                    <p class="admin-store-field-error">{{ $message }}</p>
                @enderror
                @error('staff_user_ids.*')
                    <p class="admin-store-field-error">{{ $message }}</p>
                @enderror

                <div class="admin-store-member-table-scroll">
                    <table class="admin-store-member-table">
                        <caption class="admin-visually-hidden">
                            {{ $store->name }}の所属スタッフ
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">氏名</th>
                                <th scope="col">メールアドレス</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody data-selected-users="staff">
                            @foreach ($staffMembers as $staff)
                                <tr data-selected-user data-user-id="{{ $staff->id }}">
                                    <th scope="row">
                                        {{ $staff->name }}
                                        <input
                                            type="hidden"
                                            name="staff_user_ids[]"
                                            value="{{ $staff->id }}"
                                        >
                                    </th>
                                    <td>{{ $staff->email }}</td>
                                    <td class="admin-store-member-table__action">
                                        <button
                                            class="admin-flat-button"
                                            type="button"
                                            data-remove-selected-user
                                        >
                                            所属解除
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            <tr
                                data-empty-selected-users="staff"
                                @if ($staffMembers->isNotEmpty()) hidden @endif
                            >
                                <td class="admin-store-member-table__empty" colspan="3">
                                    現在所属しているスタッフはいません。
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    class="admin-store-candidate-panel"
                    data-candidate-panel="staff"
                    hidden
                >
                    <div class="admin-store-candidate-search">
                        <label>
                            <span>未所属スタッフを氏名・メールで検索</span>
                            <input
                                type="search"
                                maxlength="100"
                                autocomplete="off"
                                data-candidate-query
                            >
                        </label>
                        <button
                            class="admin-flat-button"
                            type="button"
                            data-candidate-search
                        >
                            検索
                        </button>
                    </div>
                    <p class="admin-store-candidate-message" data-candidate-message>
                        検索語を入力してください。
                    </p>
                    <div class="admin-store-candidate-results" data-candidate-results></div>
                </div>

                <p class="admin-store-form__notice">
                    所属解除後も既存の下書き・公開シフトは保持されます。
                </p>
            </section>

            <section id="shift-managers" class="admin-store-detail-section">
                <div class="admin-store-detail-section__heading">
                    <h2>担当シフト管理者</h2>
                    @if ($canManageManagers)
                        <button
                            class="admin-flat-button"
                            type="button"
                            data-candidate-panel-toggle="manager"
                            aria-expanded="false"
                        >
                            管理者を追加
                        </button>
                    @endif
                </div>
                <p>複数のシフト管理者を担当として登録できます。</p>

                @error('manager_user_ids')
                    <p class="admin-store-field-error">{{ $message }}</p>
                @enderror
                @error('manager_user_ids.*')
                    <p class="admin-store-field-error">{{ $message }}</p>
                @enderror

                <div class="admin-store-member-table-scroll">
                    <table class="admin-store-member-table">
                        <caption class="admin-visually-hidden">
                            {{ $store->name }}の担当シフト管理者
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">氏名</th>
                                <th scope="col">メールアドレス</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody data-selected-users="manager">
                            @foreach ($shiftManagers as $manager)
                                <tr data-selected-user data-user-id="{{ $manager->id }}">
                                    <th scope="row">
                                        {{ $manager->name }}
                                        @if ($canManageManagers)
                                            <input
                                                type="hidden"
                                                name="manager_user_ids[]"
                                                value="{{ $manager->id }}"
                                            >
                                        @endif
                                    </th>
                                    <td>{{ $manager->email }}</td>
                                    <td class="admin-store-member-table__action">
                                        @if ($canManageManagers)
                                            <button
                                                class="admin-flat-button"
                                                type="button"
                                                data-remove-selected-user
                                            >
                                                担当解除
                                            </button>
                                        @else
                                            変更不可
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            <tr
                                data-empty-selected-users="manager"
                                @if ($shiftManagers->isNotEmpty()) hidden @endif
                            >
                                <td class="admin-store-member-table__empty" colspan="3">
                                    担当シフト管理者は未設定です。
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                @if ($canManageManagers)
                    <div
                        class="admin-store-candidate-panel"
                        data-candidate-panel="manager"
                        hidden
                    >
                        <div class="admin-store-candidate-search">
                            <label>
                                <span>未担当管理者を氏名・メールで検索</span>
                                <input
                                    type="search"
                                    maxlength="100"
                                    autocomplete="off"
                                    data-candidate-query
                                >
                            </label>
                            <button
                                class="admin-flat-button"
                                type="button"
                                data-candidate-search
                            >
                                検索
                            </button>
                        </div>
                        <p class="admin-store-candidate-message" data-candidate-message>
                            検索語を入力してください。
                        </p>
                        <div class="admin-store-candidate-results" data-candidate-results></div>
                    </div>
                @else
                    <p class="admin-store-form__notice">
                        担当管理者を変更できるのはシステム管理者だけです。
                    </p>
                @endif
            </section>

            <section id="shift-patterns" class="admin-store-detail-section">
                <div class="admin-store-detail-section__heading">
                    <h2>使用シフトパターン</h2>
                    <button
                        class="admin-flat-button"
                        type="button"
                        data-add-pattern
                    >
                        パターンを追加
                    </button>
                </div>
                <p>
                    勤務時間は時刻とは独立した小数値です。開始・終了時刻から計算しません。
                </p>

                @error('patterns')
                    <p class="admin-store-field-error">{{ $message }}</p>
                @enderror

                <div class="admin-store-settings-scroll">
                    <table class="admin-store-settings-table">
                        <thead>
                            <tr>
                                <th scope="col">パターンコード</th>
                                <th scope="col">開始時刻</th>
                                <th scope="col">終了時刻</th>
                                <th scope="col">勤務時間</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody data-pattern-rows>
                            @foreach ($patternRows as $index => $pattern)
                                <tr data-pattern-row>
                                    <td>
                                        @if (! empty($pattern['id']))
                                            <input
                                                type="hidden"
                                                name="patterns[{{ $index }}][id]"
                                                value="{{ $pattern['id'] }}"
                                                data-pattern-field="id"
                                            >
                                        @endif
                                        <input
                                            type="text"
                                            name="patterns[{{ $index }}][code]"
                                            value="{{ $pattern['code'] ?? '' }}"
                                            maxlength="20"
                                            data-pattern-field="code"
                                            @error("patterns.$index.code") aria-invalid="true" @enderror
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="time"
                                            name="patterns[{{ $index }}][start_time]"
                                            value="{{ $pattern['start_time'] ?? '' }}"
                                            data-pattern-field="start_time"
                                            @error("patterns.$index.start_time") aria-invalid="true" @enderror
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="time"
                                            name="patterns[{{ $index }}][end_time]"
                                            value="{{ $pattern['end_time'] ?? '' }}"
                                            data-pattern-field="end_time"
                                            @error("patterns.$index.end_time") aria-invalid="true" @enderror
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            name="patterns[{{ $index }}][work_hours]"
                                            value="{{ $pattern['work_hours'] ?? '' }}"
                                            min="0"
                                            max="9999.99"
                                            step="0.01"
                                            inputmode="decimal"
                                            data-pattern-field="work_hours"
                                            @error("patterns.$index.work_hours") aria-invalid="true" @enderror
                                            required
                                        >
                                    </td>
                                    <td>
                                        <button
                                            class="admin-flat-button"
                                            type="button"
                                            data-remove-pattern
                                        >
                                            使用解除
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            <tr
                                data-empty-patterns
                                @if ($patternRows !== []) hidden @endif
                            >
                                <td class="admin-store-member-table__empty" colspan="5">
                                    使用中のシフトパターンはありません。
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="admin-store-form__notice">
                    使用解除しても既存シフト内の勤務時間スナップショットは変更されません。
                </p>
            </section>

            <section
                id="staffing-settings"
                class="admin-store-detail-section"
                data-staffing-settings
            >
                <h2>人数配置判定</h2>
                <label class="admin-store-form__field admin-store-form__field--compact">
                    <span>判定方式</span>
                    <select name="staffing_check_mode" data-staffing-mode required>
                        @foreach ([
                            'disabled' => 'チェックなし',
                            'fixed_total' => '固定人数チェック',
                            'pattern_combinations' => '勤務パターン組合せチェック',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected($staffingMode === $value)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label
                    class="admin-store-form__field admin-store-form__field--compact"
                    data-fixed-staffing
                    @if ($staffingMode !== 'fixed_total') hidden @endif
                >
                    <span>固定必要人数</span>
                    <input
                        type="number"
                        name="required_staff_count"
                        value="{{ old('required_staff_count', $store->required_staff_count) }}"
                        min="0"
                        step="1"
                    >
                </label>

                <div
                    class="admin-store-pattern-staffing"
                    data-pattern-staffing
                    @if ($staffingMode !== 'pattern_combinations') hidden @endif
                >
                    <h3>全日共通の勤務パターン組合せ</h3>
                    <p>
                        各行が選択肢（OR条件）、同じ行内の複数パターンがAND条件です。
                    </p>
                    <div class="admin-store-settings-scroll">
                        <table class="admin-store-settings-table admin-store-staffing-table">
                            <thead>
                                <tr>
                                    <th scope="col">選択肢コード</th>
                                    <th scope="col">表示順</th>
                                    @foreach ($shiftPatterns as $pattern)
                                        <th
                                            scope="col"
                                            data-staffing-pattern-column="{{ $pattern->id }}"
                                        >
                                            {{ $pattern->code }} 必要数
                                        </th>
                                    @endforeach
                                    <th scope="col">削除</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($staffingRows as $optionIndex => $option)
                                    <tr>
                                        <td>
                                            @if (! empty($option['id']))
                                                <input
                                                    type="hidden"
                                                    name="staffing_options[{{ $optionIndex }}][id]"
                                                    value="{{ $option['id'] }}"
                                                >
                                            @endif
                                            <input
                                                type="text"
                                                name="staffing_options[{{ $optionIndex }}][code]"
                                                value="{{ $option['code'] ?? '' }}"
                                                maxlength="50"
                                                placeholder="選択肢コード"
                                            >
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                name="staffing_options[{{ $optionIndex }}][display_order]"
                                                value="{{ $option['display_order'] ?? $optionIndex + 1 }}"
                                                min="0"
                                                step="1"
                                            >
                                        </td>
                                        @foreach ($shiftPatterns as $pattern)
                                            <td data-staffing-pattern-column="{{ $pattern->id }}">
                                                <input
                                                    type="number"
                                                    name="staffing_options[{{ $optionIndex }}][pattern_counts][{{ $pattern->id }}]"
                                                    value="{{ $option['pattern_counts'][$pattern->id] ?? '' }}"
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
                                            @if (! empty($option['id']))
                                                <input
                                                    type="checkbox"
                                                    name="staffing_options[{{ $optionIndex }}][remove]"
                                                    value="1"
                                                    @checked((bool) ($option['remove'] ?? false))
                                                    aria-label="{{ $option['code'] ?? '選択肢' }}を削除"
                                                >
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <p class="admin-store-form__notice">
                    設定変更は既存の下書きシフトと公開版を直接変更しません。
                </p>
            </section>

            <div class="admin-store-save-bar">
                <p data-store-save-status aria-live="polite">
                    変更はありません
                </p>
                <button type="submit" data-store-save-button disabled>
                    保存
                </button>
            </div>
        </form>

        <template data-user-row-template>
            <tr data-selected-user>
                <th scope="row">
                    <span data-user-name></span>
                    <input type="hidden" data-user-id-input>
                </th>
                <td data-user-email></td>
                <td class="admin-store-member-table__action">
                    <button
                        class="admin-flat-button"
                        type="button"
                        data-remove-selected-user
                    ></button>
                </td>
            </tr>
        </template>

        <template data-pattern-row-template>
            <tr data-pattern-row>
                <td>
                    <input
                        type="text"
                        maxlength="20"
                        data-pattern-field="code"
                        required
                    >
                </td>
                <td>
                    <input type="time" data-pattern-field="start_time">
                </td>
                <td>
                    <input type="time" data-pattern-field="end_time">
                </td>
                <td>
                    <input
                        type="number"
                        min="0"
                        max="9999.99"
                        step="0.01"
                        inputmode="decimal"
                        data-pattern-field="work_hours"
                        required
                    >
                </td>
                <td>
                    <button
                        class="admin-flat-button"
                        type="button"
                        data-remove-pattern
                    >
                        使用解除
                    </button>
                </td>
            </tr>
        </template>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-store-management.js') }}" defer></script>
@endpush
