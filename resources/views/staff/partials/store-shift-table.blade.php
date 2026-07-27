<div class="store-shift-table-scroll" data-store-scroll>
    <table class="store-shift-table">
        <caption class="visually-hidden">
            {{ $store['name'] }}の月間シフト表
        </caption>
        <colgroup>
            <col class="store-shift-table__staff-column">
            @foreach ($calendar['days'] as $day)
                <col class="store-shift-table__date-column">
            @endforeach
        </colgroup>
        <thead>
            <tr>
                {{-- 左上セルに店舗名と店舗切り替えメニューを配置します。 --}}
                <th class="store-shift-table__corner" scope="col" rowspan="2">
                    @include('staff.partials.store-menu')
                </th>
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
                    <th class="store-shift-table__staff-name" scope="row">{{ $staff['name'] }}</th>
                    @foreach ($calendar['days'] as $day)
                        @php
                            $shift = $staff['shifts'][$day['date']] ?? null;
                        @endphp
                        <td
                            class="store-shift-table__shift-cell @if ($day['is_today']) is-today @endif"
                            data-date="{{ $day['date'] }}"
                            data-is-today="{{ $day['is_today'] ? 'true' : 'false' }}"
                        >
                            @if ($shift)
                                {{ $shift['shift_type']['code'] }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
