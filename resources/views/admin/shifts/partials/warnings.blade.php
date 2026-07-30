<section
    @class([
        'admin-warning-panel',
        'is-clear' => $screen['warningResult']['can_publish'],
    ])
    data-admin-warning-panel
    data-checked-draft-version="{{ $screen['warningResult']['checked_draft_version'] }}"
    aria-label="下書きシフトの警告"
>
    <div class="admin-warning-panel__summary" aria-live="polite">
        <strong data-admin-publish-eligibility>
            {{ $screen['warningResult']['can_publish'] ? '配布可能' : '配布不可' }}
        </strong>
        <span data-admin-warning-count>
            警告 {{ $screen['warningResult']['blocking_warning_count'] }}件
        </span>
        <span class="admin-warning-panel__checked">
            下書き版 {{ $screen['warningResult']['checked_draft_version'] }} を確認
        </span>
    </div>

    <ul
        class="admin-warning-panel__list"
        data-admin-warning-list
        @if ($screen['warningResult']['warnings'] === []) hidden @endif
    >
        @foreach ($screen['warningResult']['warnings'] as $warning)
            <li
                data-warning-code="{{ $warning['warning_code'] }}"
                data-warning-date="{{ $warning['work_date'] }}"
                @if (isset($warning['user_id']))
                    data-warning-user-id="{{ $warning['user_id'] }}"
                @endif
                aria-label="警告：{{ $warning['message'] }}"
            >
                <span aria-hidden="true">⚠</span>
                {{ $warning['message'] }}
            </li>
        @endforeach
    </ul>
</section>
