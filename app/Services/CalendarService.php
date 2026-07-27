<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * 月間カレンダーの表示用データを作成します。
 *
 * Bladeでは日付計算を行わず、このサービスが作った配列だけを描画します。
 */
class CalendarService
{
    public function __construct(
        private readonly JapaneseHolidayService $holidayService,
    ) {
    }

    /**
     * 個人カレンダーと店舗別表で共通利用する月データを返します。
     *
     * @return array<string, mixed>
     */
    public function make(string $month, string $today): array
    {
        $firstDay = new DateTimeImmutable($month.'-01');
        $lastDay = $firstDay->modify('last day of this month');
        $numberOfDays = (int) $lastDay->format('j');
        $firstWeekday = (int) $firstDay->format('w');
        // 画面の基準サイズを保つため、4週で収まる月も5週表示にします。
        $weekCount = max(5, (int) ceil(($firstWeekday + $numberOfDays) / 7));
        $totalCells = $weekCount * 7;
        $days = [];

        for ($day = 1; $day <= $numberOfDays; $day++) {
            $date = $firstDay->setDate(
                (int) $firstDay->format('Y'),
                (int) $firstDay->format('n'),
                $day,
            );
            $days[] = $this->makeDay($date, $today);
        }

        $cells = array_fill(0, $totalCells, null);

        foreach ($days as $index => $day) {
            $cells[$firstWeekday + $index] = $day;
        }

        return [
            'year' => (int) $firstDay->format('Y'),
            'month' => (int) $firstDay->format('n'),
            'month_value' => $firstDay->format('Y-m'),
            'month_label' => $firstDay->format('Y年n月'),
            'first_weekday' => $firstWeekday,
            'number_of_days' => $numberOfDays,
            'is_leap_year' => $firstDay->format('L') === '1',
            'previous_month' => $firstDay->modify('-1 month')->format('Y-m'),
            'next_month' => $firstDay->modify('+1 month')->format('Y-m'),
            'days' => $days,
            'weeks' => array_chunk($cells, 7),
        ];
    }

    /**
     * 日付単位の表示情報を作ります。
     *
     * @return array<string, mixed>
     */
    private function makeDay(DateTimeInterface $date, string $today): array
    {
        $dateValue = $date->format('Y-m-d');
        $weekday = (int) $date->format('w');
        $isSunday = $weekday === 0;
        $isSaturday = $weekday === 6;
        $isHoliday = $this->holidayService->isHoliday($dateValue);

        return [
            'date' => $dateValue,
            'day' => (int) $date->format('j'),
            'weekday' => $weekday,
            'weekday_label' => ['日', '月', '火', '水', '木', '金', '土'][$weekday],
            'is_sunday' => $isSunday,
            'is_saturday' => $isSaturday,
            'is_holiday' => $isHoliday,
            'is_today' => $dateValue === $today,
            // 当日でも日付帯は曜日・祝日の色を維持します。
            'date_class' => $isHoliday || $isSunday
                ? 'is-holiday'
                : ($isSaturday ? 'is-saturday' : 'is-weekday'),
        ];
    }
}
