<section class="personal-calendar" aria-label="スタッフ個人用月間カレンダー">
    <div class="personal-calendar__weekday-row" role="row">
        @foreach (['日', '月', '火', '水', '木', '金', '土'] as $weekday)
            <div
                class="personal-calendar__weekday @if ($loop->first) is-sunday @elseif ($loop->last) is-saturday @else is-weekday @endif"
                role="columnheader"
            >
                {{ $weekday }}
            </div>
        @endforeach
    </div>

    <div class="personal-calendar__body">
        @foreach ($calendar['weeks'] as $week)
            <div class="personal-calendar__week" role="row">
                @foreach ($week as $day)
                    @if ($day)
                        @php
                            $dayShifts = $personalShifts[$day['date']] ?? [];
                            $shift = $dayShifts[0] ?? null;
                        @endphp
                        <div class="personal-calendar__day" role="cell" data-date="{{ $day['date'] }}">
                            <div
                                class="personal-calendar__date-header {{ $day['date_class'] }}"
                                @if ($day['is_today']) aria-current="date" @endif
                            >
                                {{ $day['day'] }}
                            </div>
                            <div
                                class="personal-calendar__shift-content @if ($day['is_today']) is-today @endif"
                                data-is-today="{{ $day['is_today'] ? 'true' : 'false' }}"
                            >
                                @if ($shift)
                                    <div class="personal-calendar__store-name">{{ $shift['store_name'] }}</div>
                                    <div class="personal-calendar__shift-code">{{ $shift['shift_type']['code'] }}</div>
                                @endif
                            </div>
                        </div>
                    @else
                        @php
                            $emptyWeekday = $loop->index;
                            $emptyClass = $emptyWeekday === 0
                                ? 'is-sunday'
                                : ($emptyWeekday === 6 ? 'is-saturday' : 'is-weekday');
                        @endphp
                        <div class="personal-calendar__day is-empty" role="cell">
                            <div class="personal-calendar__date-header {{ $emptyClass }}"></div>
                            <div class="personal-calendar__shift-content"></div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
    </div>
</section>
