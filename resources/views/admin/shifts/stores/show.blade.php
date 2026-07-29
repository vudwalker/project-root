@extends('layouts.admin')

@section('title', '店舗別シフト編集｜管理画面')

@section('content')
    @include('admin.shifts.partials.toolbar', ['screenType' => 'store'])

    <section
        class="admin-shift-workspace"
        aria-labelledby="store-shift-title"
        data-shift-source="draft"
        data-store-id="{{ $screen['contextStoreId'] }}"
        data-shift-schedule-id="{{ $screen['scheduleId'] }}"
        data-store-read-only="{{ $screen['isReadOnly'] ? 'true' : 'false' }}"
        data-target-month="{{ $calendar['month_value'] }}"
        @if (! $screen['isReadOnly'])
            data-shift-editor
            data-create-shift-url="{{ route('admin.shifts.store', ['store' => $screen['contextStoreCode']]) }}"
            data-shift-url-template="{{ route('admin.shifts.update', [
                'store' => $screen['contextStoreCode'],
                'shift' => '__SHIFT_ID__',
            ]) }}"
        @endif
    >
        <h1 id="store-shift-title" class="admin-visually-hidden">
            {{ $screen['contextName'] }} {{ $calendar['month_label'] }} 店舗別シフト編集
        </h1>

        @include('admin.shifts.partials.grid', ['screenType' => 'store'])

        <div class="admin-store-controls" aria-label="シフト入力操作">
            <div class="admin-store-controls__patterns">
                @foreach ($screen['patterns'] as $pattern)
                    <button
                        class="admin-flat-button admin-pattern-button"
                        type="button"
                        data-shift-mode="pattern"
                        data-shift-pattern-id="{{ $pattern['id'] }}"
                        data-shift-pattern-code="{{ $pattern['code'] }}"
                        data-work-minutes="{{ $pattern['workMinutes'] }}"
                        aria-pressed="false"
                        @disabled($screen['isReadOnly'])
                    >
                        {{ $pattern['code'] }}
                    </button>
                @endforeach
                <button
                    class="admin-flat-button admin-pattern-button admin-pattern-button--delete"
                    type="button"
                    data-shift-mode="delete"
                    aria-pressed="false"
                    @disabled($screen['isReadOnly'])
                >
                    削除
                </button>
                <span class="admin-static-mode-status" data-shift-mode-status>入力未選択</span>
            </div>

            <div class="admin-store-controls__publish">
                <span
                    class="admin-save-status"
                    data-admin-save-status
                    aria-live="polite"
                >{{ $screen['saveStatus'] }}</span>
                <button
                    class="admin-flat-button admin-publish-button"
                    type="button"
                    disabled
                    title="静的UI確認中のため配布処理には接続していません"
                >
                    シフト配布
                </button>
            </div>
        </div>

        <p @class(['admin-publish-note', 'is-warning' => $isNg])>
            {{ $screen['publishStatus'] }}
        </p>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-shift-editor.js') }}" defer></script>
@endpush
