<?php

namespace App\Services\Admin;

/**
 * 参考画像を確認するための管理者用ダミーデータを組み立てます。
 *
 * 表示専用のため、ModelやDB更新処理には接続しません。
 */
final class StaticAdminShiftUiService
{
    /**
     * @var array<string, string>
     */
    private const STORES = [
        'daianji' => '大安寺',
        'noda' => '野田',
        'saidaiji' => '西大寺',
        'okayama-tomita' => '岡山富田',
    ];

    /**
     * @var array<string, string>
     */
    private const STAFF_MEMBERS = [
        'chikazawa' => '近澤幸次',
        'otsuki' => '大月敦弘',
        'fujimoto' => '藤本保子',
        'honyama' => '本山宏明',
        'koai' => '小合達也',
    ];

    /**
     * 静的集計用の勤務分数です。時刻・休憩入力は扱いません。
     *
     * @var array<string, int>
     */
    private const PATTERN_MINUTES = [
        'A' => 480,
        'B' => 480,
        'C' => 675,
        'D' => 60,
        'E' => 480,
        '研' => 0,
        '有' => 0,
    ];

    public function defaultStoreCode(): string
    {
        return 'daianji';
    }

    public function defaultStaffCode(): string
    {
        return 'chikazawa';
    }

    public function hasStore(string $code): bool
    {
        return array_key_exists($code, self::STORES);
    }

    public function hasStaff(string $code): bool
    {
        return array_key_exists($code, self::STAFF_MEMBERS);
    }

    /**
     * @return array<string, string>
     */
    public function stores(): array
    {
        return self::STORES;
    }

    /**
     * @return array<string, string>
     */
    public function staffMembers(): array
    {
        return self::STAFF_MEMBERS;
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    public function makeStoreScreen(string $storeCode, array $calendar, bool $isNg): array
    {
        $warningDays = $isNg ? [10, 25] : [];
        $schedules = [
            [
                'id' => 101,
                'name' => '大月敦弘',
                'days' => [2 => 'C', 5 => 'C', 9 => 'C', 12 => 'C', 16 => 'C', 19 => 'C', 23 => 'C', 26 => 'C', 30 => 'C'],
            ],
            [
                'id' => 102,
                'name' => '藤本保子',
                'days' => [4 => 'C', 11 => 'C', 17 => 'C', 18 => 'C', 24 => 'C', 25 => 'C'],
            ],
            [
                'id' => 103,
                'name' => '本山宏明',
                'days' => [1 => 'C', 3 => 'C', 6 => 'C', 8 => 'C', 15 => 'C', 20 => 'C', 22 => 'C', 27 => 'C', 29 => 'C', 31 => 'C'],
            ],
            [
                'id' => 104,
                'name' => '近澤幸次',
                'days' => [7 => 'C', 13 => 'C', 14 => 'C', 21 => 'C', 22 => 'C', 28 => 'C'],
            ],
            [
                'id' => 105,
                'name' => '小合達也',
                'days' => [10 => 'C'],
            ],
        ];

        if ($isNg) {
            // NG画像に合わせ、10日へ勤務を移して警告列を作ります。
            unset($schedules[1]['days'][11]);
            $schedules[1]['days'][10] = 'C';
        }

        $rows = $this->makeRows($schedules, $calendar, $warningDays);

        return [
            'contextName' => self::STORES[$storeCode],
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDays, true, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'patterns' => ['C', '研'],
            'saveStatus' => $isNg ? '保存済み 14:32' : '保存済み 14:32',
            'publishStatus' => $isNg ? '修正が必要・配布不可' : '配布済み 7月1日 09:00',
            'warning' => $isNg
                ? '重複勤務があります。10日・25日の対象セルを修正するまで配布できません。'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    public function makeStaffScreen(string $staffCode, array $calendar, bool $isNg): array
    {
        $warningDays = $isNg ? [22] : [];
        $schedules = [
            [
                'id' => 201,
                'name' => '西大寺',
                'days' => $isNg ? [11 => 'D', 22 => 'D'] : [11 => 'D'],
            ],
            [
                'id' => 202,
                'name' => '野田',
                'days' => [8 => 'C', 12 => 'C', 20 => 'C', 26 => 'C'],
            ],
            [
                'id' => 203,
                'name' => '大安寺',
                'days' => [7 => 'C', 13 => 'C', 14 => 'C', 21 => 'C', 22 => 'C', 28 => 'C'],
            ],
            [
                'id' => 204,
                'name' => '岡山富田',
                'days' => [31 => 'C'],
            ],
            [
                'id' => 205,
                'name' => '',
                'days' => [],
                'isSpacer' => true,
            ],
        ];

        $rows = $this->makeRows($schedules, $calendar, $warningDays);

        return [
            'contextName' => self::STAFF_MEMBERS[$staffCode],
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDays, false, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'saveStatus' => '最終更新 7月1日 14:32',
            'publishStatus' => $isNg ? '修正が必要・配布不可' : '配布済み 7月1日 09:00',
            'warning' => $isNg
                ? '22日に西大寺と大安寺の勤務が重複しています。店舗別画面で修正してください。'
                : null,
        ];
    }

    /**
     * @param  array<int, array{id: int, name: string, days: array<int, string|array<int, string>>}>  $schedules
     * @param  array<string, mixed>  $calendar
     * @param  array<int, int>  $warningDays
     * @return array<int, array<string, mixed>>
     */
    private function makeRows(array $schedules, array $calendar, array $warningDays): array
    {
        $rows = [];

        foreach ($schedules as $schedule) {
            $cells = [];
            $codesForTotal = [];

            foreach ($calendar['days'] as $day) {
                $rawCodes = $schedule['days'][$day['day']] ?? [];
                $codes = is_array($rawCodes) ? array_values($rawCodes) : [$rawCodes];

                foreach ($codes as $code) {
                    $codesForTotal[] = $code;
                }

                $cells[$day['date']] = [
                    'codes' => $codes,
                    'isWarning' => in_array($day['day'], $warningDays, true),
                ];
            }

            $rows[] = [
                'id' => $schedule['id'],
                'name' => $schedule['name'],
                'cells' => $cells,
                'monthlyTotal' => $this->makeMonthlyTotal($codesForTotal),
                'isSpacer' => $schedule['isSpacer'] ?? false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  array<int, int>  $warningDays
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{mark: string, active: bool, isWarning: bool}>
     */
    private function makeDailyStatuses(
        array $calendar,
        array $warningDays,
        bool $alwaysActive,
        array $rows,
    ): array {
        $statuses = [];

        foreach ($calendar['days'] as $day) {
            $isWarning = in_array($day['day'], $warningDays, true);
            $hasShift = false;

            foreach ($rows as $row) {
                if ($row['cells'][$day['date']]['codes'] !== []) {
                    $hasShift = true;
                    break;
                }
            }

            $statuses[$day['date']] = [
                'mark' => $isWarning ? '×' : '○',
                'active' => $alwaysActive || $hasShift,
                'isWarning' => $isWarning,
            ];
        }

        return $statuses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function aggregateRows(array $rows): array
    {
        $minutes = 0;
        $counts = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);
        $total = 0;

        foreach ($rows as $row) {
            $minutes += $row['monthlyTotal']['minutes'];
            $total += $row['monthlyTotal']['total'];

            foreach ($counts as $code => $count) {
                $counts[$code] += $row['monthlyTotal']['counts'][$code];
            }
        }

        return [
            'minutes' => $minutes,
            'time' => $this->formatMinutes($minutes),
            'counts' => $counts,
            'total' => $total,
        ];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array{minutes: int, time: string, counts: array<string, int>, total: int}
     */
    private function makeMonthlyTotal(array $codes): array
    {
        $minutes = 0;
        $counts = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);

        foreach ($codes as $code) {
            $minutes += self::PATTERN_MINUTES[$code] ?? 0;

            if (array_key_exists($code, $counts)) {
                $counts[$code]++;
            }
        }

        return [
            'minutes' => $minutes,
            'time' => $this->formatMinutes($minutes),
            'counts' => $counts,
            'total' => count($codes),
        ];
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
