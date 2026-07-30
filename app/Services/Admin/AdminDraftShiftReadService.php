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

/**
 * 管理者用UIへ表示する下書きシフトを、副作用を発生させずに読み取ります。
 */
final class AdminDraftShiftReadService
{
    public function __construct(
        private readonly DraftShiftWarningService $warningService,
        private readonly AdminDraftShiftScreenProjector $screenProjector,
    ) {}

    /**
     * @return Collection<int, Store>
     */
    public function accessibleStores(User $actor): Collection
    {
        $query = Store::query()
            ->where('organization_id', $actor->organization_id)
            ->orderBy('display_order')
            ->orderBy('name')
            ->orderBy('id');

        if ($actor->hasRole('system_admin')) {
            return $query->get();
        }

        $today = CarbonImmutable::now((string) config('app.timezone', 'Asia/Tokyo'))
            ->toDateString();

        return $query
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

        $currentMembers = $store->staffMembers()
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

        $draftUserIds = Shift::query()
            ->whereHas(
                'schedule',
                fn (Builder $query): Builder => $query
                    ->where('store_id', $store->getKey())
                    ->whereDate('target_month', $monthStart),
            )
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->distinct()
            ->pluck('user_id');
        $currentUserIds = $currentMembers->modelKeys();
        $draftOnlyMembers = User::query()
            ->select(['users.id', 'users.name', 'users.status'])
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereKey($draftUserIds)
            ->when(
                $currentUserIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn(
                    'users.id',
                    $currentUserIds,
                ),
            )
            ->whereHas(
                'roles',
                fn (Builder $builder): Builder => $builder->where(
                    'roles.code',
                    'staff',
                ),
            )
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get();

        return new Collection([
            ...$currentMembers->all(),
            ...$draftOnlyMembers->all(),
        ]);
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
                            'work_hours',
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
        $warningDates = $this->screenProjector->warningDates($warnings);
        $rows = $this->screenProjector->makeStaffRows(
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
            ->get(['id', 'code', 'work_hours'])
            ->map(fn (StoreShiftPattern $pattern): array => [
                'id' => (int) $pattern->getKey(),
                'code' => $pattern->code,
                'workHours' => (string) $pattern->work_hours,
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
            'isReadOnly' => false,
            'hasBlockingWarnings' => $warningResult['blocking_warning_count'] > 0,
            'canPublish' => $warningResult['can_publish'],
            'publicationState' => $this->screenProjector->publicationState($schedule),
            'publishedVersion' => $schedule?->published_version,
            'publishedDraftVersion' => $schedule?->published_draft_version,
            'publishedAt' => $schedule?->published_at?->toIso8601String(),
            'publishedByUserId' => $schedule?->published_by_user_id,
            'rows' => $rows,
            'dailyStatuses' => $this->screenProjector->makeDailyStatuses(
                $calendar,
                $warningDates,
                true,
                $rows,
            ),
            'monthlyTotal' => $this->screenProjector->aggregateRows($rows),
            'patterns' => $patterns,
            'saveStatus' => $this->screenProjector->storeSaveStatus($schedule),
            'publishStatus' => $this->screenProjector->publicationStatusLabel(
                $schedule,
                $warningResult,
            ),
            'warning' => $this->screenProjector->warningSummary($warningResult),
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
        $warningDates = $this->screenProjector->warningDates($warnings);

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
                'dailyStatuses' => $this->screenProjector->makeDailyStatuses(
                    $calendar,
                    $warningDates,
                    false,
                    [],
                ),
                'monthlyTotal' => $this->screenProjector->emptyMonthlyTotal(),
                'saveStatus' => '下書きシフトなし',
                'publishStatus' => $this->screenProjector->publishEligibilityLabel(
                    $warningResult,
                ),
                'warning' => $this->screenProjector->warningSummary($warningResult),
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
                'work_hours',
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
                'schedule:id,store_id,target_month,shift_updated_at,draft_version,published_version,published_draft_version,published_at',
                'schedule.store:id,name,display_order',
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
        $rows = $this->screenProjector->makeStoreRows(
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
            'dailyStatuses' => $this->screenProjector->makeDailyStatuses(
                $calendar,
                $warningDates,
                false,
                $rows,
            ),
            'monthlyTotal' => $this->screenProjector->aggregateRows($rows),
            'saveStatus' => $this->screenProjector->staffSaveStatus($shifts),
            'publishStatus' => $this->screenProjector->publishEligibilityLabel(
                $warningResult,
            ),
            'warning' => $this->screenProjector->warningSummary($warningResult),
            'warningResult' => $warningResult,
        ];
    }
}
