{{--
    このdata-store-scrollをJavaScriptが見つけ、初回表示時に当日列へ移動します。
    表はCSSで横スクロールでき、日付セルの幅は縮めません。
--}}
<div class="store-shift-table-scroll" data-store-scroll>
    <table class="store-shift-table">
        {{-- captionは画面には見せず、表の内容を支援技術へ伝えます。 --}}
        <caption class="visually-hidden">
            {{ $store['name'] }}の月間シフト表
        </caption>
        {{-- 先頭を氏名列、その後を1日ごとの固定幅列として定義します。 --}}
        <colgroup>
            <col class="store-shift-table__staff-column">
            @foreach ($calendar['days'] as $day)
                <col class="store-shift-table__date-column">
            @endforeach
        </colgroup>
        <thead>
            <tr>
                {{--
                    左上セルは日付・曜日の2行分を結合します。
                    CSSのstickyにより、横スクロール時も氏名列と一緒に残ります。
                --}}
                <th class="store-shift-table__corner" scope="col" rowspan="2">
                    @include('staff.partials.store-menu')
                </th>
                {{-- 1行目は日付です。date_classで土日・祝日の色を変えます。 --}}
                @foreach ($calendar['days'] as $day)
                    <th
                        class="store-shift-table__date-header {{ $day['date_class'] }}"
                        scope="col"
                        data-date="{{ $day['date'] }}"
                        @if ($day['is_today']) data-is-today="true" @endif
                    >
                        {{ $day['day'] }}
                    </th>
                @endforeach
            </tr>
            <tr>
                {{-- 2行目は曜日です。 --}}
                @foreach ($calendar['days'] as $day)
                    <th class="store-shift-table__weekday-header {{ $day['date_class'] }}" scope="col">
                        {{ $day['weekday_label'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($store['staff'] as $staff)
                <tr>
                    {{-- scope="row"により、このセルが1行分の見出し（氏名）だと示します。 --}}
                    <th class="store-shift-table__staff-name" scope="row">{{ $staff['name'] }}</th>
                    @foreach ($calendar['days'] as $day)
                        @php
                            // スタッフと日付に一致するシフトを取得します。未登録ならnullです。
                            $shift = $staff['shifts'][$day['date']] ?? null;
                        @endphp
                        <td
                            class="store-shift-table__shift-cell @if ($day['is_today']) is-today @endif"
                            data-date="{{ $day['date'] }}"
                            data-is-today="{{ $day['is_today'] ? 'true' : 'false' }}"
                        >
                            @if ($shift)
                                {{-- Cなどの固定値ではなく、Serviceから渡された区分コードです。 --}}
                                {{ $shift['shift_type']['code'] }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
