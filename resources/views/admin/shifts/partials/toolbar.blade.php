<header class="admin-shift-toolbar">
    <nav
        class="admin-month-navigation"
        aria-label="対象月切り替え"
        data-admin-month-navigation
        data-target-month="{{ $monthNavigation['selectedMonth'] }}"
    >
        <div class="admin-month-navigation__primary">
            @if ($monthNavigation['previousUrl'])
                <a
                    class="admin-month-navigation__arrow"
                    href="{{ $monthNavigation['previousUrl'] }}"
                    aria-label="前月"
                    data-admin-month-link
                >◁</a>
            @else
                <span
                    class="admin-month-navigation__arrow is-disabled"
                    aria-label="これより前の月は選択できません"
                    aria-disabled="true"
                    data-month-boundary="minimum"
                >◁</span>
            @endif
            <span class="admin-month-navigation__label">{{ $calendar['month_label'] }}</span>
            @if ($monthNavigation['nextUrl'])
                <a
                    class="admin-month-navigation__arrow"
                    href="{{ $monthNavigation['nextUrl'] }}"
                    aria-label="翌月"
                    data-admin-month-link
                >▷</a>
            @else
                <span
                    class="admin-month-navigation__arrow is-disabled"
                    aria-label="これより先の月は選択できません"
                    aria-disabled="true"
                    data-month-boundary="maximum"
                >▷</span>
            @endif
        </div>

        <form
            class="admin-month-navigation__selector"
            method="GET"
            action="{{ $monthNavigation['formAction'] }}"
            data-admin-month-form
        >
            @foreach ($monthNavigation['hiddenQuery'] as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach

            <label>
                <span class="admin-visually-hidden">年</span>
                <select name="year" aria-label="表示する年" data-month-year>
                    @foreach ($monthNavigation['selectableYears'] as $year)
                        <option
                            value="{{ $year }}"
                            data-months="{{ implode(',', $monthNavigation['selectableMonthsByYear'][$year]) }}"
                            @selected($year === $monthNavigation['selectedYear'])
                        >{{ $year }}年</option>
                    @endforeach
                </select>
            </label>

            <label>
                <span class="admin-visually-hidden">月</span>
                <select name="month_number" aria-label="表示する月" data-month-number>
                    @foreach ($monthNavigation['selectableMonthsByYear'][$monthNavigation['selectedYear']] as $monthNumber)
                        <option
                            value="{{ $monthNumber }}"
                            @selected($monthNumber === $monthNavigation['selectedMonthNumber'])
                        >{{ $monthNumber }}月</option>
                    @endforeach
                </select>
            </label>

            <button type="submit">表示</button>
            <a href="{{ $monthNavigation['currentUrl'] }}" data-admin-month-link>今月</a>
        </form>

        <span
            class="admin-month-navigation__error"
            role="alert"
            data-admin-month-navigation-error
            hidden
        ></span>
    </nav>

    @if ($screenType === 'store')
        <details class="admin-toolbar-menu">
            <summary class="admin-toolbar-menu__button">店舗切換</summary>
            <div class="admin-toolbar-menu__panel">
                @foreach ($contextOptions as $option)
                    <a
                        class="admin-toolbar-menu__link"
                        href="{{ $option['url'] }}"
                        data-admin-shift-navigation-link
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
            data-admin-shift-navigation-link
            @class(['admin-view-switch__link', 'is-current' => $screenType === 'store'])
            @if ($screenType === 'store') aria-current="page" @endif
        >
            店舗別
        </a>
        <a
            href="{{ $navigation['staffView'] }}"
            data-admin-shift-navigation-link
            @class(['admin-view-switch__link', 'is-current' => $screenType === 'staff'])
            @if ($screenType === 'staff') aria-current="page" @endif
        >
            スタッフ別
        </a>
    </nav>

    <div class="admin-screen-status" role="status">
        <span>{{ $screen['saveStatus'] }}</span>
        <span class="admin-screen-status__separator">／</span>
        <span>{{ $screen['publishStatus'] }}</span>
    </div>
</header>
