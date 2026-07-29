<div class="admin-shift-grid-scroll" tabindex="0" aria-label="月間シフト表。横方向と縦方向にスクロールできます">
    <table @class(['admin-shift-grid', 'admin-shift-grid--staff' => $screenType === 'staff'])>
        <caption class="admin-visually-hidden">
            {{ $calendar['month_label'] }} {{ $screen['contextName'] }}
            {{ $screenType === 'store' ? '店舗別シフト編集表' : 'スタッフ別シフト確認表' }}
        </caption>
        <colgroup>
            <col class="admin-shift-grid__name-column">
            @foreach ($calendar['days'] as $day)
                <col class="admin-shift-grid__date-column">
            @endforeach
            <col class="admin-shift-grid__time-column">
            @foreach (['A', 'B', 'C', 'D', 'E'] as $code)
                <col class="admin-shift-grid__total-column">
            @endforeach
            <col class="admin-shift-grid__grand-total-column">
        </colgroup>
        <thead>
            <tr>
                <th class="admin-shift-grid__corner" rowspan="2" scope="col">
                    @include('admin.shifts.partials.context-menu')
                </th>
                @foreach ($calendar['days'] as $day)
                    <th
                        @class([
                            'admin-shift-grid__date-header',
                            'is-saturday' => $day['is_saturday'],
                            'is-holiday' => $day['is_sunday'] || $day['is_holiday'],
                            'is-today' => $day['is_today'],
                        ])
                        data-shift-date="{{ $day['date'] }}"
                        scope="col"
                    >
                        {{ $day['day'] }}
                    </th>
                @endforeach
                <th class="admin-shift-grid__monthly-title admin-monthly-start admin-monthly-top" rowspan="2" scope="col">
                    月間計
                </th>
                <th class="admin-shift-grid__monthly-spacer admin-monthly-top admin-monthly-end" colspan="5"></th>
                <th class="admin-shift-grid__grand-total-header" rowspan="2" scope="col">
                    <span class="admin-visually-hidden">総数</span>
                </th>
            </tr>
            <tr>
                @foreach ($calendar['days'] as $day)
                    <th
                        @class([
                            'admin-shift-grid__weekday-header',
                            'is-saturday' => $day['is_saturday'],
                            'is-holiday' => $day['is_sunday'] || $day['is_holiday'],
                            'is-today' => $day['is_today'],
                        ])
                        scope="col"
                    >
                        {{ $day['weekday_label'] }}
                    </th>
                @endforeach
                @foreach (['A', 'B', 'C', 'D', 'E'] as $code)
                    <th
                        @class([
                            'admin-shift-grid__monthly-code',
                            'admin-monthly-end' => $loop->last,
                        ])
                        scope="col"
                    >
                        {{ $code }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($screen['rows'] as $row)
                <tr
                    @if ($screenType === 'store')
                        data-user-id="{{ $row['id'] }}"
                    @else
                        data-store-id="{{ $row['id'] }}"
                    @endif
                >
                    <th class="admin-shift-grid__row-name" scope="row">{{ $row['name'] }}</th>
                    @foreach ($calendar['days'] as $day)
                        @php($cell = $row['cells'][$day['date']])
                        <td
                            @class([
                                'admin-shift-grid__shift-cell',
                                'is-warning' => $cell['isWarning'],
                                'is-today' => $day['is_today'],
                            ])
                            data-shift-date="{{ $day['date'] }}"
                        >
                            @foreach ($cell['codes'] as $code)
                                <span class="admin-shift-grid__shift-code">{{ $code }}</span>
                            @endforeach
                        </td>
                    @endforeach
                    <td
                        @class([
                            'admin-shift-grid__monthly-value',
                            'admin-monthly-start',
                            'admin-monthly-bottom' => $loop->last,
                        ])
                    >
                        {{ $row['isSpacer'] ? '' : $row['monthlyTotal']['time'] }}
                    </td>
                    @foreach (['A', 'B', 'C', 'D', 'E'] as $code)
                        <td
                            @class([
                                'admin-shift-grid__monthly-value',
                                'admin-monthly-end' => $loop->last,
                                'admin-monthly-bottom' => $loop->parent->last,
                            ])
                        >
                            {{ $row['monthlyTotal']['counts'][$code] ?: '' }}
                        </td>
                    @endforeach
                    <td class="admin-shift-grid__grand-total-value">
                        <span class="admin-visually-hidden">総数</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="admin-shift-grid__footer-name"></td>
                @foreach ($calendar['days'] as $day)
                    @php($status = $screen['dailyStatuses'][$day['date']])
                    <td
                        @class([
                            'admin-shift-grid__daily-status',
                            'is-active' => $status['active'],
                            'is-warning' => $status['isWarning'],
                        ])
                        data-shift-date="{{ $day['date'] }}"
                    >
                        @if ($status['active'])
                            <span aria-label="{{ $status['isWarning'] ? '確認不合格' : '確認済み' }}">
                                {{ $status['mark'] }}
                            </span>
                        @endif
                    </td>
                @endforeach
                <td class="admin-shift-grid__monthly-footer admin-shift-grid__monthly-footer--time admin-summary-start">
                    {{ $screen['monthlyTotal']['time'] }}
                </td>
                @foreach (['A', 'B', 'C', 'D', 'E'] as $code)
                    <td class="admin-shift-grid__monthly-footer">
                        {{ $screen['monthlyTotal']['counts'][$code] }}
                    </td>
                @endforeach
                <td class="admin-shift-grid__grand-total-footer">
                    {{ $screen['monthlyTotal']['total'] }}
                </td>
            </tr>
        </tfoot>
    </table>
</div>
