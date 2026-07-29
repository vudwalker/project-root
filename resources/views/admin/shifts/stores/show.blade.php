@extends('layouts.admin')

@section('title', '店舗別シフト編集｜管理画面')

@section('content')
    @include('admin.shifts.partials.toolbar', ['screenType' => 'store'])

    <section
        class="admin-shift-workspace"
        aria-labelledby="store-shift-title"
        data-shift-source="draft"
    >
        <h1 id="store-shift-title" class="admin-visually-hidden">
            {{ $screen['contextName'] }} {{ $calendar['month_label'] }} 店舗別シフト編集
        </h1>

        @include('admin.shifts.partials.grid', ['screenType' => 'store'])

        <div class="admin-store-controls" aria-label="静的な入力操作見本">
            <div class="admin-store-controls__patterns">
                @foreach ($screen['patterns'] as $pattern)
                    <button
                        class="admin-flat-button admin-pattern-button"
                        type="button"
                        data-static-shift-mode="{{ $pattern }}"
                        aria-pressed="false"
                    >
                        {{ $pattern }}
                    </button>
                @endforeach
                <button
                    class="admin-flat-button admin-pattern-button admin-pattern-button--delete"
                    type="button"
                    data-static-shift-mode="delete"
                    aria-pressed="false"
                >
                    削除
                </button>
                <span class="admin-static-mode-status" data-static-mode-status>入力未選択</span>
            </div>

            <div class="admin-store-controls__publish">
                <span class="admin-save-status">{{ $screen['saveStatus'] }}</span>
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
