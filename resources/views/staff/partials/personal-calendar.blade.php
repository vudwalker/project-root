<section class="personal-calendar" aria-label="スタッフ個人用月間カレンダー">
    {{-- 曜日見出しは日曜日から土曜日までの7列です。 --}}
    <div class="personal-calendar__weekday-row" role="row">
        @foreach (['日', '月', '火', '水', '木', '金', '土'] as $weekday)
            <div
                {{-- 最初を日曜色、最後を土曜色、それ以外を平日色にします。 --}}
                class="personal-calendar__weekday @if ($loop->first) is-sunday @elseif ($loop->last) is-saturday @else is-weekday @endif"
                role="columnheader"
            >
                {{ $weekday }}
            </div>
        @endforeach
    </div>

    <div class="personal-calendar__body">
        {{-- Serviceで作成した週単位の配列を、上から1週ずつ表示します。 --}}
        @foreach ($calendar['weeks'] as $week)
            <div class="personal-calendar__week" role="row">
                @foreach ($week as $day)
                    @if ($day)
                        @php
                            /*
                             * 日付をキーにしてその日のシフトを取得します。
                             * 現在は、最初の1件を個人カレンダーへ表示します。
                             */
                            $dayShifts = $personalShifts[$day['date']] ?? [];
                            $shift = $dayShifts[0] ?? null;
                        @endphp
                        <div class="personal-calendar__day" role="cell" data-date="{{ $day['date'] }}">
                            {{-- 日付の帯。date_classには平日・土曜・日曜・祝日の色分けが入ります。 --}}
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
                                    {{-- 店舗名とシフト区分コードはServiceから渡された値を表示します。 --}}
                                    <div class="personal-calendar__store-name">{{ $shift['store_name'] }}</div>
                                    <div class="personal-calendar__shift-code">{{ $shift['shift_type']['code'] }}</div>
                                @endif
                            </div>
                        </div>
                    @else
                        @php
                            /*
                             * 月初・月末の「日付がないマス」も7列の幅を保つため表示します。
                             * 空マスにも曜日に合った背景色を付けます。
                             */
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
