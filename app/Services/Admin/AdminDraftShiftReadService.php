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
    public function __construct(
        private readonly DraftShiftWarningService $warningService,
    ) {}

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
            ->whereHas(
                'roles',
                fn (Builder $builder): Builder => $builder->where(
                    'roles.code',
                    'staff',
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
        $warningResult = $this->warningService->evaluate($store, $targetMonth);
        $warnings = $warningResult['warnings'];
        $warningDates = $this->warningDates($warnings);
        $rows = $this->makeStaffRows(
            $staffMembers,
            $store,
            $calendar,
            $shifts,
            $warnings,
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
            'draftVersion' => (int) ($schedule?->draft_version ?? 0),
            'hasSchedule' => $schedule !== null,
            'hasStaff' => $staffMembers->isNotEmpty(),
            'emptyMessage' => $staffMembers->isEmpty() ? '所属スタッフがいません' : null,
            'isReadOnly' => ! $store->isActive(),
            'hasBlockingWarnings' => $warningResult['blocking_warning_count'] > 0,
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDates, true, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'patterns' => $patterns,
            'saveStatus' => $this->storeSaveStatus($schedule),
            'publishStatus' => $this->publishEligibilityLabel($warningResult),
            'warning' => $this->warningSummary($warningResult),
            'warningResult' => $warningResult,
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
        $warningResult = $this->warningService->evaluate(
            $filterStore,
            $targetMonth,
        );
        $warnings = $warningResult['warnings'];
        $warningDates = $this->warningDates($warnings);

        if (! $staff) {
            return [
                'contextName' => '所属スタッフなし',
                'contextStoreId' => (int) $filterStore->getKey(),
                'contextUserId' => null,
                'hasStaff' => false,
                'emptyMessage' => '所属スタッフがいません',
                'isReadOnly' => true,
                'hasBlockingWarnings' => $warningResult['blocking_warning_count'] > 0,
                'rows' => [],
                'dailyStatuses' => $this->makeDailyStatuses(
                    $calendar,
                    $warningDates,
                    false,
                    [],
                ),
                'monthlyTotal' => $this->emptyMonthlyTotal(),
                'saveStatus' => '下書きシフトなし',
                'publishStatus' => $this->publishEligibilityLabel($warningResult),
                'warning' => $this->warningSummary($warningResult),
                'warningResult' => $warningResult,
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
        $rows = $this->makeStoreRows(
            $stores,
            $staff,
            $calendar,
            $shifts,
            $warnings,
        );

        return [
            'contextName' => $staff->name,
            'contextStoreId' => (int) $filterStore->getKey(),
            'contextUserId' => (int) $staff->getKey(),
            'hasStaff' => true,
            'emptyMessage' => null,
            'isReadOnly' => true,
            'hasBlockingWarnings' => $warningResult['blocking_warning_count'] > 0,
            'rows' => $rows,
            'dailyStatuses' => $this->makeDailyStatuses($calendar, $warningDates, false, $rows),
            'monthlyTotal' => $this->aggregateRows($rows),
            'saveStatus' => $this->staffSaveStatus($shifts),
            'publishStatus' => $this->publishEligibilityLabel($warningResult),
            'warning' => $this->warningSummary($warningResult),
            'warningResult' => $warningResult,
        ];
    }

    /**
     * @param  Collection<int, User>  $staffMembers
     * @param  array<string, mixed>  $calendar
     * @param  Collection<int, Shift>  $shifts
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private function makeStaffRows(
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
    private function makeStoreRows(
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
     * @param  list<array<string, mixed>>  $warnings
     * @return list<string>
     */
    private function warningDates(array $warnings): array
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
    private function publishEligibilityLabel(array $warningResult): string
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
    private function warningSummary(array $warningResult): ?string
    {
        if ($warningResult['can_publish']) {
            return null;
        }

        return sprintf(
            '下書きに配布を止める警告が%d件あります。',
            $warningResult['blocking_warning_count'],
        );
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
