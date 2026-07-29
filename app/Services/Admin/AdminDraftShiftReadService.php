<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 管理者用UIへ表示する下書きシフトを、副作用なしで読み取って投影します。
 */
final class AdminDraftShiftReadService
{
    /**
     * @return Collection<int, Store>
     */
    public function accessibleStores(User $actor): Collection
    {
        $query = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id');

        if ($actor->hasRole('system_admin')) {
            return $query->get();
        }

        $today = CarbonImmutable::now((string) config('app.timezone', 'Asia/Tokyo'))
            ->toDateString();

        return $query
            ->where('status', 'active')
            ->whereHas('shiftManagers', function (Builder $builder) use ($actor, $today): void {
                $builder
                    ->whereKey($actor->getKey())
                    ->where('store_shift_manager.is_active', true)
                    ->where(function (Builder $period) use ($today): void {
                        $period
                            ->whereNull('store_shift_manager.started_on')
                            ->orWhereDate('store_shift_manager.started_on', '<=', $today);
                    })
                    ->where(function (Builder $period) use ($today): void {
                        $period
                            ->whereNull('store_shift_manager.ended_on')
                            ->orWhereDate('store_shift_manager.ended_on', '>=', $today);
                    });
            })
            ->get();
    }

    /**
     * 対象店舗・対象月に有効な所属を持つスタッフを安定順で取得します。
     *
     * @return Collection<int, User>
     */
    public function staffForStore(Store $store, CarbonImmutable $targetMonth): Collection
    {
        $monthStart = $targetMonth->startOfMonth()->toDateString();
        $monthEnd = $targetMonth->endOfMonth()->toDateString();

        return $store->staffMembers()
            ->select(['users.id', 'users.name', 'users.status'])
            ->where('users.status', 'active')
            ->whereDoesntHave(
                'roles',
                fn (Builder $builder): Builder => $builder->whereIn(
                    'roles.code',
                    ['shift_manager', 'system_admin'],
                ),
            )
            ->wherePivot('is_active', true)
            ->where(function (Builder $period) use ($monthEnd): void {
                $period
                    ->whereNull('store_user.started_on')
                    ->orWhereDate('store_user.started_on', '<=', $monthEnd);
            })
            ->where(function (Builder $period) use ($monthStart): void {
                $period
                    ->whereNull('store_user.ended_on')
                    ->orWhereDate('store_user.ended_on', '>=', $monthStart);
            })
            ->orderBy('store_user.display_order')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  Collection<int, User>  $staffMembers
     * @return array<string, mixed>
     */
    public function makeStoreScreen(
        Store $store,
        CarbonImmutable $targetMonth,
        array $calendar,
        Collection $staffMembers,
        bool $isNg,
    ): array {
        $staffIds = $staffMembers->modelKeys();
        $schedule = ShiftSchedule::query()
            ->where('store_id', $store->getKey())
            ->whereDate('target_month', $targetMonth->toDateString())
            ->with([
                'shifts' => function ($builder) use ($staffIds): void {
                    $builder
                        ->select([
                            'id',
                            'shift_schedule_id',
                            'user_id',
                            'work_date',
                            'store_shift_pattern_id',
                            'sequence',
                            'entry_uuid',
                            'pattern_code',
                            'work_minutes',
                        ])
                        ->when(
                            $staffIds === [],
                            fn ($query) => $query->whereRaw('1 = 0'),
                            fn ($query) => $query->whereIn('user_id', $staffIds),
                        )
                        ->orderBy('user_id')
                        ->orderBy('work_date')
                        ->orderBy('sequence')
                        ->orderBy('id');
                },
            ])
            ->first();
        $shifts = $schedule?->shifts ?? new Collection;
        $warningDates = $isNg
            ? $this->datesForDays($targetMonth, [10, 25])
            : [];
        $rows = $this->makeStaffRows(
            $staffMembers,
            $store,
            $calendar,
            $shifts,
            $warningDates,
        );
        $patterns = StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('code')
            ->get(['id', 'code', 'work_minutes'])
            ->map(fn (StoreShiftPattern $pattern): array => [
                'id' => (int) $pattern->getKey(),
                'code' => $pattern->code,
                'workMinutes' => (int) $pattern->work_minutes,
            ])
            ->all();

        return [
            'contextName' => $store->name,
            'contextStoreId' => (int) $store->getKey(),
            'contextStoreCode' => $store->code,
            'scheduleId' => $schedule?->getKey(),
            'hasSchedule' => $schedule !== null,
            'hasStaff' => $staffMembers->isNotEmpty(),
            'emptyMessage' => $staffMembers->isEmpty() ? '所属スタッフがいません' : null,
            'isReadOnly' => ! $store->isActive(),
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDates, true, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'patterns' => $patterns,
            'saveStatus' => $this->storeSaveStatus($schedule),
            'publishStatus' => $this->storePublishStatus($schedule),
            'warning' => $isNg
                ? '修正が必要な下書きがあります。読み取り接続では警告状態を更新しません。'
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @return array<string, mixed>
     */
    public function makeStaffScreen(
        Store $filterStore,
        ?User $staff,
        CarbonImmutable $targetMonth,
        array $calendar,
        bool $isNg,
    ): array {
        if (! $staff) {
            return [
                'contextName' => '所属スタッフなし',
                'contextStoreId' => (int) $filterStore->getKey(),
                'contextUserId' => null,
                'hasStaff' => false,
                'emptyMessage' => '所属スタッフがいません',
                'isReadOnly' => true,
                'rows' => [],
                'dailyStatuses' => $this->makeDailyStatuses($calendar, [], false, []),
                'monthlyTotal' => $this->emptyMonthlyTotal(),
                'saveStatus' => '下書きシフトなし',
                'publishStatus' => '閲覧専用',
                'warning' => null,
            ];
        }

        $monthStart = $targetMonth->startOfMonth()->toDateString();
        $monthEnd = $targetMonth->endOfMonth()->toDateString();
        $shifts = Shift::query()
            ->select([
                'id',
                'shift_schedule_id',
                'user_id',
                'work_date',
                'store_shift_pattern_id',
                'sequence',
                'entry_uuid',
                'pattern_code',
                'work_minutes',
            ])
            ->where('user_id', $staff->getKey())
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->whereHas('schedule', function (Builder $builder) use ($filterStore, $targetMonth): void {
                $builder
                    ->whereDate('target_month', $targetMonth->toDateString())
                    ->whereHas('store', function (Builder $storeQuery) use ($filterStore): void {
                        $storeQuery->where(
                            'organization_id',
                            $filterStore->organization_id,
                        );
                    });
            })
            ->with([
                'schedule:id,store_id,target_month,shift_updated_at,draft_version,published_version,published_at',
                'schedule.store:id,name,status,display_order',
            ])
            ->orderBy('work_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $stores = $shifts
            ->map(fn (Shift $shift): ?Store => $shift->schedule?->store)
            ->filter()
            ->push($filterStore)
            ->unique(fn (Store $store): int => (int) $store->getKey())
            ->sortBy(fn (Store $store): string => sprintf(
                '%010d|%s|%010d',
                $store->display_order,
                $store->name,
                $store->getKey(),
            ))
            ->values();
        $warningDates = $isNg
            ? $this->datesForDays($targetMonth, [22])
            : [];
        $rows = $this->makeStoreRows(
            $stores,
            $staff,
            $calendar,
            $shifts,
            $warningDates,
        );

        return [
            'contextName' => $staff->name,
            'contextStoreId' => (int) $filterStore->getKey(),
            'contextUserId' => (int) $staff->getKey(),
            'hasStaff' => true,
            'emptyMessage' => null,
            'isReadOnly' => true,
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDates, false, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'saveStatus' => $this->staffSaveStatus($shifts),
            'publishStatus' => '下書き表示・閲覧専用',
            'warning' => $isNg
                ? '修正が必要な下書きがあります。管理者用店舗別シフト編集画面で確認してください。'
                : null,
        ];
    }

    /**
     * @param  Collection<int, User>  $staffMembers
     * @param  array<string, mixed>  $calendar
     * @param  Collection<int, Shift>  $shifts
     * @param  list<string>  $warningDates
     * @return list<array<string, mixed>>
     */
    private function makeStaffRows(
        Collection $staffMembers,
        Store $store,
        array $calendar,
        Collection $shifts,
        array $warningDates,
    ): array {
        $shiftsByCell = $shifts->groupBy(
            fn (Shift $shift): string => $shift->user_id.'|'.$shift->work_date->toDateString(),
        );

        return $staffMembers
            ->map(function (User $staff) use (
                $store,
                $calendar,
                $shiftsByCell,
                $warningDates,
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
                        $warningDates,
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
     * @param  list<string>  $warningDates
     * @return list<array<string, mixed>>
     */
    private function makeStoreRows(
        SupportCollection $stores,
        User $staff,
        array $calendar,
        Collection $shifts,
        array $warningDates,
    ): array {
        $shiftsByCell = $shifts->groupBy(
            fn (Shift $shift): string => $shift->schedule->store_id.'|'.$shift->work_date->toDateString(),
        );

        return $stores
            ->map(function (Store $store) use (
                $staff,
                $calendar,
                $shiftsByCell,
                $warningDates,
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
                        $warningDates,
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
     * @param  SupportCollection<int, Shift>  $shifts
     * @param  list<string>  $warningDates
     * @return array<string, mixed>
     */
    private function makeCell(
        SupportCollection $shifts,
        int $userId,
        int $storeId,
        string $shiftDate,
        array $warningDates,
    ): array {
        $orderedShifts = $shifts
            ->sortBy([
                ['sequence', 'asc'],
                ['id', 'asc'],
            ])
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
            'isWarning' => in_array($shiftDate, $warningDates, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $calendar
     * @param  list<string>  $warningDates
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array{mark: string, active: bool, isWarning: bool}>
     */
    private function makeDailyStatuses(
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
    private function aggregateRows(array $rows): array
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

    /**
     * @return array{minutes: int, time: string, counts: array<string, int>, total: int}
     */
    private function emptyMonthlyTotal(): array
    {
        return [
            'minutes' => 0,
            'time' => '0:00',
            'counts' => array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0),
            'total' => 0,
        ];
    }

    /**
     * @param  list<int>  $days
     * @return list<string>
     */
    private function datesForDays(CarbonImmutable $targetMonth, array $days): array
    {
        return collect($days)
            ->filter(fn (int $day): bool => $day <= $targetMonth->daysInMonth)
            ->map(fn (int $day): string => $targetMonth->setDay($day)->toDateString())
            ->values()
            ->all();
    }

    private function storeSaveStatus(?ShiftSchedule $schedule): string
    {
        if (! $schedule) {
            return '下書き未作成';
        }

        if (! $schedule->shift_updated_at) {
            return '下書き0件';
        }

        return '最終更新 '.$schedule->shift_updated_at->format('n月j日 H:i');
    }

    private function storePublishStatus(?ShiftSchedule $schedule): string
    {
        if (! $schedule || $schedule->published_version === null) {
            return '未配布';
        }

        return $schedule->published_version === $schedule->draft_version
            ? '配布済み'
            : '再配布が必要';
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     */
    private function staffSaveStatus(Collection $shifts): string
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

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
