<?php

namespace App\Services\Admin;

use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 管理者用下書きの警告と配布可否をまとめて計算します。
 */
final class DraftShiftWarningService
{
    public function __construct(
        private readonly DuplicateShiftWarningDetector $duplicateDetector,
        private readonly PatternStaffingWarningEvaluator $staffingEvaluator,
    ) {}

    /**
     * @return array{
     *     can_publish: bool,
     *     blocking_warning_count: int,
     *     warnings: list<array<string, mixed>>,
     *     checked_draft_version: int,
     *     checked_draft_versions: array<int, int>,
     *     checked_at: string
     * }
     */
    public function evaluate(
        Store $store,
        CarbonImmutable $targetMonth,
    ): array {
        $monthStart = $targetMonth->startOfMonth();
        $monthEnd = $targetMonth->endOfMonth();
        $targetSchedule = $this->loadTargetSchedule($store, $targetMonth);
        $targetShifts = $targetSchedule?->shifts ?? new Collection;
        $duplicateCandidateShifts = $this->loadDuplicateCandidateShifts(
            $store,
            $targetMonth,
            $targetSchedule,
        );
        $requirements = $this->loadRequirements($store, $monthStart, $monthEnd);
        $patternsByCode = StoreShiftPattern::query()
            ->where('store_id', $store->getKey())
            ->whereIn('code', PatternStaffingWarningEvaluator::TARGET_CODES)
            ->get([
                'id',
                'store_id',
                'code',
                'start_time',
                'start_day_offset',
                'end_time',
                'end_day_offset',
            ])
            ->keyBy('code');

        $warnings = collect([
            ...$this->duplicateDetector->detect($store, $duplicateCandidateShifts),
            ...$this->staffingEvaluator->evaluate(
                $store,
                $monthStart,
                $monthEnd,
                $targetShifts,
                $requirements,
                $patternsByCode,
            ),
        ])
            ->sortBy(fn (array $warning): string => sprintf(
                '%s|%s|%010d',
                $warning['work_date'],
                $warning['warning_code'],
                $warning['user_id'] ?? 0,
            ))
            ->values()
            ->all();
        $blockingWarningCount = collect($warnings)
            ->where('blocking', true)
            ->count();

        return [
            'can_publish' => $blockingWarningCount === 0,
            'blocking_warning_count' => $blockingWarningCount,
            'warnings' => $warnings,
            'checked_draft_version' => (int) ($targetSchedule?->draft_version ?? 0),
            'checked_draft_versions' => $this->checkedDraftVersions(
                $targetSchedule,
                $duplicateCandidateShifts,
            ),
            'checked_at' => now(
                (string) config('app.timezone', 'Asia/Tokyo'),
            )->toIso8601String(),
        ];
    }

    /**
     * 対象店舗で表示月に出勤予定があるスタッフだけを、人数判定に必要な
     * ロール・対象店舗所属とともに読み込みます。
     */
    private function loadTargetSchedule(
        Store $store,
        CarbonImmutable $targetMonth,
    ): ?ShiftSchedule {
        $schedule = ShiftSchedule::query()
            ->select(['id', 'store_id', 'target_month', 'draft_version'])
            ->where('store_id', $store->getKey())
            ->whereDate('target_month', $targetMonth->startOfMonth()->toDateString())
            ->with([
                'shifts' => function ($query) use ($targetMonth): void {
                    $query
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
                        ->whereDate(
                            'work_date',
                            '>=',
                            $targetMonth->startOfMonth()->toDateString(),
                        )
                        ->whereDate(
                            'work_date',
                            '<=',
                            $targetMonth->endOfMonth()->toDateString(),
                        );
                },
                'shifts.user:id,organization_id,name,status,deleted_at',
                'shifts.user.roles:id,code',
                'shifts.user.stores' => function ($query) use ($store): void {
                    $query
                        ->select(['stores.id', 'stores.organization_id', 'stores.name'])
                        ->where('stores.id', $store->getKey());
                },
            ])
            ->first();

        if (! $schedule instanceof ShiftSchedule) {
            return null;
        }

        $schedule->setRelation('store', $store);
        $schedule->shifts->each(
            fn (Shift $shift): Shift => $shift->setRelation('schedule', $schedule),
        );

        return $schedule;
    }

    /**
     * 対象店舗の出勤予定と同じスタッフ・同じ勤務基準日の下書きだけを、
     * 同一組織の他店舗から取得します。
     *
     * @return Collection<int, Shift>
     */
    private function loadDuplicateCandidateShifts(
        Store $store,
        CarbonImmutable $targetMonth,
        ?ShiftSchedule $targetSchedule,
    ): Collection {
        if (
            ! $targetSchedule instanceof ShiftSchedule
            || $targetSchedule->shifts->isEmpty()
        ) {
            return new Collection;
        }

        $monthStart = $targetMonth->startOfMonth()->toDateString();
        $monthEnd = $targetMonth->endOfMonth()->toDateString();
        $crossStoreShifts = Shift::query()
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
            ->whereBetween('work_date', [$monthStart, $monthEnd])
            ->whereHas('schedule', function (Builder $query) use (
                $store,
                $monthStart,
            ): void {
                $query
                    ->whereDate('target_month', $monthStart)
                    ->where('store_id', '<>', $store->getKey())
                    ->whereHas('store', function (Builder $storeQuery) use ($store): void {
                        $storeQuery->where(
                            'organization_id',
                            $store->organization_id,
                        );
                    });
            })
            ->whereExists(function ($query) use ($targetSchedule): void {
                $query
                    ->selectRaw('1')
                    ->from('shifts as target_shifts')
                    ->whereColumn('target_shifts.user_id', 'shifts.user_id')
                    ->whereColumn('target_shifts.work_date', 'shifts.work_date')
                    ->where(
                        'target_shifts.shift_schedule_id',
                        $targetSchedule->getKey(),
                    );
            })
            ->with([
                'schedule:id,store_id,target_month,draft_version',
                'schedule.store:id,organization_id,name,code,display_order',
            ])
            ->orderBy('user_id')
            ->orderBy('work_date')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $targetUsers = $targetSchedule->shifts
            ->mapWithKeys(fn (Shift $shift): array => [
                (int) $shift->user_id => $shift->user,
            ]);
        $crossStoreShifts->each(
            fn (Shift $shift): Shift => $shift->setRelation(
                'user',
                $targetUsers->get((int) $shift->user_id),
            ),
        );

        return new Collection([
            ...$targetSchedule->shifts->all(),
            ...$crossStoreShifts->all(),
        ]);
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, int>
     */
    private function checkedDraftVersions(
        ?ShiftSchedule $targetSchedule,
        Collection $shifts,
    ): array {
        $versions = $shifts
            ->mapWithKeys(fn (Shift $shift): array => [
                (int) $shift->schedule->store_id => (int) $shift->schedule->draft_version,
            ]);

        if ($targetSchedule instanceof ShiftSchedule) {
            $versions->put(
                (int) $targetSchedule->store_id,
                (int) $targetSchedule->draft_version,
            );
        }

        return $versions->sortKeys()->all();
    }

    /**
     * @return Collection<int, StoreStaffingRequirement>
     */
    private function loadRequirements(
        Store $store,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): Collection {
        return StoreStaffingRequirement::query()
            ->where('store_id', $store->getKey())
            ->where('is_active', true)
            ->where(function (Builder $query) use ($monthEnd): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $monthEnd->toDateString());
            })
            ->where(function (Builder $query) use ($monthStart): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $monthStart->toDateString());
            })
            ->with([
                'options.patterns.shiftPattern:id,store_id,code,start_time,start_day_offset,end_time,end_day_offset',
            ])
            ->get();
    }
}
