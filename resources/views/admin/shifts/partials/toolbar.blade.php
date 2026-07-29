<header class="admin-shift-toolbar">
    <nav class="admin-month-navigation" aria-label="対象月切り替え">
        <a class="admin-month-navigation__arrow" href="{{ $navigation['previous'] }}" aria-label="前月">
            ◁
        </a>
        <span class="admin-month-navigation__label">{{ $calendar['month_label'] }}</span>
        <a class="admin-month-navigation__arrow" href="{{ $navigation['next'] }}" aria-label="翌月">
            ▷
        </a>
    </nav>

    @if ($screenType === 'store')
        <details class="admin-toolbar-menu">
            <summary class="admin-toolbar-menu__button">店舗切換</summary>
            <div class="admin-toolbar-menu__panel">
                @foreach ($contextOptions as $option)
                    <a
                        class="admin-toolbar-menu__link"
                        href="{{ $option['url'] }}"
                        @if ($option['current']) aria-current="page" @endif
                    >
                        {{ $option['label'] }}
                    </a>
                @endforeach
            </div>
        </details>
    @endif

    <nav class="admin-view-switch" aria-label="管理者用シフト画面切り替え">
        <a
            href="{{ $navigation['storeView'] }}"
            @class(['admin-view-switch__link', 'is-current' => $screenType === 'store'])
            @if ($screenType === 'store') aria-current="page" @endif
        >
            店舗別
        </a>
        <a
            href="{{ $navigation['staffView'] }}"
            @class(['admin-view-switch__link', 'is-current' => $screenType === 'staff'])
            @if ($screenType === 'staff') aria-current="page" @endif
        >
            スタッフ別
        </a>
    </nav>

    <div @class(['admin-screen-status', 'is-warning' => $isNg]) role="{{ $isNg ? 'alert' : 'status' }}">
        @if ($screen['warning'])
            <strong>修正が必要：</strong>{{ $screen['warning'] }}
        @else
            <span>{{ $screen['saveStatus'] }}</span>
            <span class="admin-screen-status__separator">／</span>
            <span>{{ $screen['publishStatus'] }}</span>
        @endif
    </div>
</header>
