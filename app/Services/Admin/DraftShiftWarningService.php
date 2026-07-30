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
        $schedules = $this->loadOrganizationSchedules($store, $targetMonth);
        $shifts = $schedules
            ->flatMap(fn (ShiftSchedule $schedule): Collection => $schedule->shifts)
            ->values();
        $targetSchedule = $schedules->first(
            fn (ShiftSchedule $schedule): bool => (int) $schedule->store_id
                === (int) $store->getKey(),
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
            ...$this->duplicateDetector->detect($store, $shifts),
            ...$this->staffingEvaluator->evaluate(
                $store,
                $monthStart,
                $monthEnd,
                $shifts,
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
            'checked_draft_versions' => $schedules
                ->mapWithKeys(fn (ShiftSchedule $schedule): array => [
                    (int) $schedule->store_id => (int) $schedule->draft_version,
                ])
                ->all(),
            'checked_at' => now(
                (string) config('app.timezone', 'Asia/Tokyo'),
            )->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, ShiftSchedule>
     */
    private function loadOrganizationSchedules(
        Store $store,
        CarbonImmutable $targetMonth,
    ): Collection {
        $schedules = ShiftSchedule::query()
            ->whereDate('target_month', $targetMonth->startOfMonth()->toDateString())
            ->whereHas('store', function (Builder $query) use ($store): void {
                $query->where('organization_id', $store->organization_id);
            })
            ->with([
                'store:id,organization_id,name,code,display_order',
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
                'shifts.user.stores:id,organization_id,name',
            ])
            ->orderBy('store_id')
            ->get();

        // hasMany側から読み込んだシフトへ親を明示し、警告計算中のN+1を防ぎます。
        $schedules->each(function (ShiftSchedule $schedule): void {
            $schedule->shifts->each(
                fn (Shift $shift): Shift => $shift->setRelation('schedule', $schedule),
            );
        });

        return $schedules;
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
