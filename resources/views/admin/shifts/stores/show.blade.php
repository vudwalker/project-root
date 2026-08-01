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
        data-draft-version="{{ $screen['draftVersion'] }}"
        data-store-read-only="{{ $screen['isReadOnly'] ? 'true' : 'false' }}"
        data-target-month="{{ $calendar['month_value'] }}"
        data-can-publish="{{ $screen['canPublish'] ? 'true' : 'false' }}"
        data-publication-state="{{ $screen['publicationState'] }}"
        data-published-version="{{ $screen['publishedVersion'] }}"
        data-published-draft-version="{{ $screen['publishedDraftVersion'] }}"
        data-published-at="{{ $screen['publishedAt'] }}"
        data-published-by-user-id="{{ $screen['publishedByUserId'] }}"
        @if (! $screen['isReadOnly'])
            data-shift-editor
            data-create-shift-url="{{ route('admin.shifts.store', ['store' => $screen['contextStoreCode']]) }}"
            data-publish-shifts-url="{{ route('admin.shifts.publish', ['store' => $screen['contextStoreCode']]) }}"
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

        @include('admin.shifts.partials.warnings')

        <div class="admin-store-controls" aria-label="シフト入力操作">
            <a
                class="admin-flat-button"
                href="{{ route('admin.shifts.members', [
                    'store' => $screen['contextStoreCode'],
                    'month' => $calendar['month_value'],
                ]) }}"
            >
                月次表示スタッフ管理
            </a>
            <div class="admin-store-controls__patterns">
                @foreach ($screen['patterns'] as $pattern)
                    <button
                        class="admin-flat-button admin-pattern-button"
                        type="button"
                        data-shift-mode="pattern"
                        data-shift-pattern-id="{{ $pattern['id'] }}"
                        data-shift-pattern-code="{{ $pattern['code'] }}"
                        data-work-hours="{{ $pattern['workHours'] }}"
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
                @if (! $screen['isReadOnly'])
                    <button
                        class="admin-flat-button admin-publish-button"
                        type="button"
                        @disabled(
                            ! $screen['canPublish']
                            || ! $screen['hasSchedule']
                            || $screen['publicationState'] === 'published'
                        )
                    >
                        @if ($screen['publicationState'] === 'published')
                            配布済み
                        @elseif ($screen['publicationState'] === 'requires_republish')
                            再配布
                        @else
                            シフト配布
                        @endif
                    </button>
                @endif
            </div>
        </div>

        <div
            class="admin-conflict-notice"
            data-admin-conflict-notice
            role="alert"
            aria-live="assertive"
            hidden
        >
            <span>
                別の画面または別の管理者によってシフトが更新されました。
                この画面の変更は保存されていません。最新状態を確認するため再読み込みしてください。
            </span>
            <button
                class="admin-flat-button admin-conflict-notice__reload"
                type="button"
                data-admin-conflict-reload
            >
                再読み込み
            </button>
        </div>

        <p @class([
            'admin-publish-note',
            'is-warning' => $screen['hasBlockingWarnings'],
        ])>
            {{ $screen['publishStatus'] }}
        </p>
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('js/admin-shift-request.js') }}" defer></script>
    <script src="{{ asset('js/admin-shift-autosave.js') }}" defer></script>
    <script src="{{ asset('js/admin-shift-view.js') }}" defer></script>
    <script src="{{ asset('js/admin-shift-warning.js') }}" defer></script>
    <script src="{{ asset('js/admin-shift-publication.js') }}" defer></script>
    <script
        src="{{ asset('js/admin-shift-editor.js') }}?v={{ filemtime(public_path('js/admin-shift-editor.js')) }}"
        defer
    ></script>
@endpush
