@extends('layouts.admin')

@section('title', '月次表示スタッフ管理')

@push('styles')
    <link
        rel="stylesheet"
        href="{{ asset('css/admin-shift-members.css') }}?v={{ filemtime(public_path('css/admin-shift-members.css')) }}"
    >
@endpush

@section('content')
    <section
        class="admin-shift-members"
        data-monthly-members
        data-target-month="{{ $screen['targetMonth'] }}"
        data-monthly-members-version="{{ $screen['monthlyMembersVersion'] }}"
        data-add-url="{{ route('admin.shifts.members.add', ['store' => $screen['storeCode']]) }}"
        data-remove-url-template="{{ route('admin.shifts.members.remove', [
            'store' => $screen['storeCode'],
            'user' => '__USER_ID__',
        ]) }}"
        data-reorder-url="{{ route('admin.shifts.members.reorder', ['store' => $screen['storeCode']]) }}"
    >
        <header class="admin-shift-members__heading">
            <div>
                <p class="admin-shift-members__eyebrow">{{ $screen['storeName'] }}</p>
                <h1>月次表示スタッフ管理</h1>
                <p>{{ $screen['targetMonth'] }}のシフト表に表示するスタッフを管理します。</p>
            </div>
            <a class="admin-shift-members__back" href="{{ $shiftEditorUrl }}">
                シフト画面に戻る
            </a>
        </header>

        <nav class="admin-shift-members__months" aria-label="対象月">
            @if ($monthNavigation['previousUrl'])
                <a href="{{ $monthNavigation['previousUrl'] }}">前月</a>
            @endif
            <strong>{{ $monthNavigation['selectedMonth'] }}</strong>
            @if ($monthNavigation['nextUrl'])
                <a href="{{ $monthNavigation['nextUrl'] }}">翌月</a>
            @endif
        </nav>

        <p class="admin-shift-members__status" data-members-status role="status" aria-live="polite">
            保存済み
        </p>

        <div class="admin-shift-members__panel">
            <section aria-labelledby="monthly-members-title">
                <h2 id="monthly-members-title">現在の月次表示スタッフ</h2>
                <p class="admin-shift-members__hint">
                    並び順はシフト表の行順です。店舗所属を解除しても、既存シフトがある行は残ります。
                </p>
                <ol class="admin-shift-members__list" data-members-list>
                    @forelse ($screen['members'] as $member)
                        <li
                            class="admin-shift-members__item"
                            data-member-id="{{ $member['id'] }}"
                        >
                            <span class="admin-shift-members__name">{{ $member['name'] }}</span>
                            @if (! $member['canCreateShifts'])
                                <span class="admin-shift-members__badge">新規追加不可</span>
                            @endif
                            <span class="admin-shift-members__actions">
                                <button type="button" data-member-move="up">↑</button>
                                <button type="button" data-member-move="down">↓</button>
                                <button type="button" data-member-remove>除外</button>
                            </span>
                        </li>
                    @empty
                        <li class="admin-shift-members__empty" data-members-empty>
                            月次表示スタッフはまだいません。
                        </li>
                    @endforelse
                </ol>
            </section>

            <section aria-labelledby="monthly-member-candidates-title">
                <h2 id="monthly-member-candidates-title">追加候補</h2>
                <p class="admin-shift-members__hint">
                    対象月に勤務可能なactive・staffのみ追加できます。
                </p>
                <div class="admin-shift-members__add">
                    <label for="monthly-member-candidate">スタッフ</label>
                    <select id="monthly-member-candidate" data-member-candidate>
                        <option value="">スタッフを選択</option>
                        @foreach ($screen['candidates'] as $candidate)
                            <option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" data-member-add>追加</button>
                </div>
            </section>
        </div>

        @if ($screen['existingOnly'] !== [])
            <section class="admin-shift-members__legacy" aria-labelledby="existing-only-title">
                <h2 id="existing-only-title">月次対象外だが既存シフトがあるスタッフ</h2>
                <p class="admin-shift-members__hint">
                    既存シフトを保持するため表示しています。新しいシフトは追加できません。
                </p>
                <ul>
                    @foreach ($screen['existingOnly'] as $member)
                        <li>{{ $member['name'] }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="admin-shift-members__conflict" data-members-conflict hidden role="alert">
            別の画面で月次表示スタッフが更新されました。最新状態を読み込んでください。
            <button type="button" data-members-reload>再読み込み</button>
        </div>
    </section>
@endsection

@push('scripts')
    <script
        src="{{ asset('js/admin-shift-members.js') }}?v={{ filemtime(public_path('js/admin-shift-members.js')) }}"
        defer
    ></script>
@endpush
