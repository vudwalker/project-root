<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 管理者用下書きシフトを画面表示用の配列へ投影します。
 */
final class AdminDraftShiftScreenProjector
{
    /**
     * @param  Collection<int, User>  $staffMembers
     * @param  array<string, mixed>  $calendar
     * @param  Collection<int, Shift>  $shifts
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    public function makeStaffRows(
        Collection $staffMembers,
        Store $store,
        array $calendar,
        Collection $shifts,
        array $warnings,
    ): array {
        $shiftsByCell = $shifts->groupBy(
            fn (Shift $shift): string => $shift->user_id.'|'.$shift->work_date->toDateString(),
        );

        return $staffMembers
            ->map(function (User $staff) use (
                $store,
                $calendar,
                $shiftsByCell,
                $warnings,
            ): array {
                $rowShifts = new Collection;
                $cells = [];

                foreach ($calendar['days'] as $day) {
                    $cellShifts = $shiftsByCell->get(
                        $staff->getKey().'|'.$day['date'],
                        new Collection,
                    );
                    $rowShifts = $rowShifts->concat($cellShifts);
                    $cells[$day['date']] = $this->makeCell(
                        $cellShifts,
                        $staff->getKey(),
                        $store->getKey(),
                        $day['date'],
                        $warnings,
                    );
                }

                return [
                    'id' => (int) $staff->getKey(),
                    'userId' => (int) $staff->getKey(),
                    'storeId' => (int) $store->getKey(),
                    'name' => $staff->name,
                    'cells' => $cells,
                    'monthlyTotal' => $this->makeMonthlyTotal($rowShifts),
                    'isSpacer' => false,
                ];
            })
            ->all();
    }

    /**
     * @param  SupportCollection<int, Store>  $stores
     * @param  array<string, mixed>  $calendar
     * @param  Collection<int, Shift>  $shifts
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    public function makeStoreRows(
        SupportCollection $stores,
        User $staff,
        array $calendar,
        Collection $shifts,
        array $warnings,
    ): array {
        $shiftsByCell = $shifts->groupBy(
            fn (Shift $shift): string => $shift->schedule->store_id.'|'.$shift->work_date->toDateString(),
        );

        return $stores
            ->map(function (Store $store) use (
                $staff,
                $calendar,
                $shiftsByCell,
                $warnings,
            ): array {
                $rowShifts = new Collection;
                $cells = [];

                foreach ($calendar['days'] as $day) {
                    $cellShifts = $shiftsByCell->get(
                        $store->getKey().'|'.$day['date'],
                        new Collection,
                    );
                    $rowShifts = $rowShifts->concat($cellShifts);
                    $cells[$day['date']] = $this->makeCell(
                        $cellShifts,
                        $staff->getKey(),
                        $store->getKey(),
                        $day['date'],
                        $warnings,
                    );
                }

                return [
                    'id' => (int) $store->getKey(),
                    'userId' => (int) $staff->getKey(),
                    'storeId' => (int) $store->getKey(),
                    'name' => $store->name,
                    'cells' => $cells,
                    'monthlyTotal' => $this->makeMonthlyTotal($rowShifts),
                    'isSpacer' => false,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  list<string>  $warningDates
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{mark: string, active: bool, isWarning: bool}>
     */
    public function makeDailyStatuses(
        array $calendar,
        array $warningDates,
        bool $alwaysActive,
        array $rows,
    ): array {
        $statuses = [];

        foreach ($calendar['days'] as $day) {
            $isWarning = in_array($day['date'], $warningDates, true);
            $hasShift = collect($rows)->contains(
                fn (array $row): bool => $row['cells'][$day['date']]['shifts'] !== [],
            );

            $statuses[$day['date']] = [
                'mark' => $isWarning ? '×' : '○',
                'active' => $alwaysActive || $hasShift,
                'isWarning' => $isWarning,
            ];
        }

        return $statuses;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{minutes: int, time: string, counts: array<string, int>, total: int}
     */
    public function aggregateRows(array $rows): array
    {
        $minutes = 0;
        $counts = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);
        $total = 0;

        foreach ($rows as $row) {
            $minutes += $row['monthlyTotal']['minutes'];
            $total += $row['monthlyTotal']['total'];

            foreach (array_keys($counts) as $code) {
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
     * @return array{minutes: int, time: string, counts: array<string, int>, total: int}
     */
    public function emptyMonthlyTotal(): array
    {
        return [
            'minutes' => 0,
            'time' => '0:00',
            'counts' => array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0),
            'total' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return list<string>
     */
    public function warningDates(array $warnings): array
    {
        return collect($warnings)
            ->pluck('work_date')
            ->filter(fn (mixed $date): bool => is_string($date))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $warningResult
     */
    public function publishEligibilityLabel(array $warningResult): string
    {
        if ($warningResult['can_publish']) {
            return '配布可能';
        }

        return sprintf(
            '配布不可（警告%d件）',
            $warningResult['blocking_warning_count'],
        );
    }

    /**
     * @param  array<string, mixed>  $warningResult
     */
    public function warningSummary(array $warningResult): ?string
    {
        if ($warningResult['can_publish']) {
            return null;
        }

        return sprintf(
            '下書きに配布を止める警告が%d件あります。',
            $warningResult['blocking_warning_count'],
        );
    }

    public function storeSaveStatus(?ShiftSchedule $schedule): string
    {
        if (! $schedule) {
            return '下書き未作成';
        }

        if (! $schedule->shift_updated_at) {
            return '下書き0件';
        }

        return '最終更新 '.$schedule->shift_updated_at->format('n月j日 H:i');
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    public function staffSaveStatus(Collection $shifts): string
    {
        $updatedAt = $shifts
            ->map(fn (Shift $shift) => $shift->schedule?->shift_updated_at)
            ->filter()
            ->sortDesc()
            ->first();

        return $updatedAt
            ? '最終更新 '.$updatedAt->format('n月j日 H:i')
            : '下書きシフトなし';
    }

    /**
     * @param  SupportCollection<int, Shift>  $shifts
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function makeCell(
        SupportCollection $shifts,
        int $userId,
        int $storeId,
        string $shiftDate,
        array $warnings,
    ): array {
        $orderedShifts = $shifts
            ->sortBy([
                ['sequence', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $cellWarnings = collect($warnings)
            ->filter(function (array $warning) use (
                $userId,
                $storeId,
                $shiftDate,
            ): bool {
                if ($warning['work_date'] !== $shiftDate) {
                    return false;
                }

                $storeIds = $warning['store_ids'] ?? [$warning['store_id'] ?? null];

                if (! in_array($storeId, $storeIds, true)) {
                    return false;
                }

                return ! isset($warning['user_id'])
                    || (int) $warning['user_id'] === $userId;
            })
            ->values();

        return [
            'userId' => $userId,
            'storeId' => $storeId,
            'shiftDate' => $shiftDate,
            'codes' => $orderedShifts->pluck('pattern_code')->all(),
            'shifts' => $orderedShifts
                ->map(fn (Shift $shift): array => [
                    'id' => (int) $shift->getKey(),
                    'userId' => (int) $shift->user_id,
                    'storeId' => $storeId,
                    'shiftDate' => $shift->work_date->toDateString(),
                    'entryUuid' => (string) $shift->entry_uuid,
                    'sequence' => (int) $shift->sequence,
                    'shiftPatternId' => (int) $shift->store_shift_pattern_id,
                    'code' => $shift->pattern_code,
                    'workMinutes' => (int) $shift->work_minutes,
                ])
                ->all(),
            'isWarning' => $cellWarnings->isNotEmpty(),
            'warningCodes' => $cellWarnings->pluck('warning_code')->unique()->all(),
            'warningMessage' => $cellWarnings->pluck('message')->implode(' '),
        ];
    }

    /**
     * @param  SupportCollection<int, Shift>  $shifts
     * @return array{minutes: int, time: string, counts: array<string, int>, total: int}
     */
    private function makeMonthlyTotal(SupportCollection $shifts): array
    {
        $minutes = (int) $shifts->sum('work_minutes');
        $counts = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);

        foreach ($shifts as $shift) {
            if (array_key_exists($shift->pattern_code, $counts)) {
                $counts[$shift->pattern_code]++;
            }
        }

        return [
            'minutes' => $minutes,
            'time' => $this->formatMinutes($minutes),
            'counts' => $counts,
            'total' => $shifts->count(),
        ];
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
