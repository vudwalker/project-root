<nav
    class="month-navigation"
    aria-label="対象月切り替え"
    data-staff-month-navigation
>
    <div class="month-navigation__primary">
        {{-- 通常リンクのため、JavaScriptが無効でも前月・翌月へ移動できます。 --}}
        @if ($monthNavigation['previousUrl'])
            <a
                class="month-navigation__arrow"
                href="{{ $monthNavigation['previousUrl'] }}"
                aria-label="前月を表示"
            >◁</a>
        @else
            <span
                class="month-navigation__arrow is-disabled"
                aria-label="これより前の月は選択できません"
                aria-disabled="true"
                data-month-boundary="minimum"
            >◁</span>
        @endif
        <span class="month-navigation__label">{{ $calendar['month_label'] }}</span>
        @if ($monthNavigation['nextUrl'])
            <a
                class="month-navigation__arrow"
                href="{{ $monthNavigation['nextUrl'] }}"
                aria-label="翌月を表示"
            >▷</a>
        @else
            <span
                class="month-navigation__arrow is-disabled"
                aria-label="これより先の月は選択できません"
                aria-disabled="true"
                data-month-boundary="maximum"
            >▷</span>
        @endif
    </div>

    <form
        class="month-navigation__selector"
        method="GET"
        action="{{ $monthNavigation['formAction'] }}"
        data-staff-month-form
    >
        @foreach ($monthNavigation['hiddenQuery'] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <label class="month-navigation__field">
            <span class="visually-hidden">年</span>
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

        <label class="month-navigation__field">
            <span class="visually-hidden">月</span>
            <select name="month_number" aria-label="表示する月" data-month-number>
                @foreach ($monthNavigation['selectableMonthsByYear'][$monthNavigation['selectedYear']] as $monthNumber)
                    <option
                        value="{{ $monthNumber }}"
                        @selected($monthNumber === $monthNavigation['selectedMonthNumber'])
                    >{{ $monthNumber }}月</option>
                @endforeach
            </select>
        </label>

        <button class="month-navigation__button" type="submit">表示</button>
        <a class="month-navigation__button" href="{{ $monthNavigation['currentUrl'] }}">今月</a>
    </form>
</nav>
