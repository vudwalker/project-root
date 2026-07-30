<?php

namespace App\Services\Admin;

use App\Enums\DraftShiftWarningCode;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 管理者用下書きの警告と配布可否を一元的に計算します。
 */
final class DraftShiftWarningService
{
    private const TARGET_CODES = ['B', 'C', 'D'];

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
            ->whereIn('code', self::TARGET_CODES)
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
            ...$this->duplicateWarnings($store, $shifts),
            ...$this->staffingWarnings(
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
                'store:id,organization_id,name,code,status,display_order',
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
                            'work_minutes',
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

    /**
     * @param  SupportCollection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function duplicateWarnings(
        Store $targetStore,
        SupportCollection $shifts,
    ): array {
        return $shifts
            ->groupBy(fn (Shift $shift): string => $shift->user_id
                .'|'.$shift->work_date->toDateString())
            ->filter(function (SupportCollection $group) use ($targetStore): bool {
                return $group->count() >= 2
                    && $group->contains(
                        fn (Shift $shift): bool => (int) $shift->schedule->store_id
                            === (int) $targetStore->getKey(),
                    );
            })
            ->map(function (SupportCollection $group) use ($targetStore): array {
                /** @var Shift $first */
                $first = $group->first();
                $stores = $group
                    ->map(fn (Shift $shift): Store => $shift->schedule->store)
                    ->unique(fn (Store $store): int => (int) $store->getKey())
                    ->sortBy('display_order')
                    ->values();
                $isCrossStore = $stores->count() > 1;
                $staffName = $first->user?->name ?? 'スタッフID '.$first->user_id;
                $workDate = $first->work_date->toDateString();
                $dateLabel = $first->work_date->format('n月j日');
                $message = $isCrossStore
                    ? sprintf(
                        '%sさんは%sに%sの複数店舗へ登録されています。',
                        $staffName,
                        $dateLabel,
                        $stores->pluck('name')->implode('と'),
                    )
                    : sprintf(
                        '%sさんは%sに複数のシフトが登録されています。',
                        $staffName,
                        $dateLabel,
                    );

                return [
                    'warning_code' => $isCrossStore
                        ? DraftShiftWarningCode::CrossStoreDuplicate->value
                        : DraftShiftWarningCode::SameStoreDuplicate->value,
                    'severity' => 'error',
                    'blocking' => true,
                    'organization_id' => (int) $targetStore->organization_id,
                    'user_id' => (int) $first->user_id,
                    'staff_name' => $staffName,
                    'work_date' => $workDate,
                    'store_id' => (int) $targetStore->getKey(),
                    'store_ids' => $stores
                        ->map(fn (Store $store): int => (int) $store->getKey())
                        ->all(),
                    'store_names' => $stores->pluck('name')->all(),
                    'shift_ids' => $group
                        ->sortBy([['sequence', 'asc'], ['id', 'asc']])
                        ->pluck('id')
                        ->map(fn (int $id): int => $id)
                        ->all(),
                    'shift_patterns' => $group
                        ->sortBy([['sequence', 'asc'], ['id', 'asc']])
                        ->map(fn (Shift $shift): array => [
                            'shift_id' => (int) $shift->getKey(),
                            'store_id' => (int) $shift->schedule->store_id,
                            'code' => (string) $shift->pattern_code,
                        ])
                        ->values()
                        ->all(),
                    'message' => $message,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  SupportCollection<int, Shift>  $organizationShifts
     * @param  Collection<int, StoreStaffingRequirement>  $requirements
     * @param  SupportCollection<string, StoreShiftPattern>  $patternsByCode
     * @return list<array<string, mixed>>
     */
    private function staffingWarnings(
        Store $store,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        SupportCollection $organizationShifts,
        Collection $requirements,
        SupportCollection $patternsByCode,
    ): array {
        if ($store->staffing_check_mode !== 'pattern_combinations') {
            return [];
        }

        $targetShiftsByDate = $organizationShifts
            ->filter(
                fn (Shift $shift): bool => (int) $shift->schedule->store_id
                    === (int) $store->getKey(),
            )
            ->groupBy(fn (Shift $shift): string => $shift->work_date->toDateString());
        $warnings = [];

        for ($date = $monthStart; $date->lte($monthEnd); $date = $date->addDay()) {
            $requirement = $this->applicableRequirement($requirements, $date);

            if (
                ! $requirement instanceof StoreStaffingRequirement
                || $requirement->options->isEmpty()
                || ! $this->hasUsableOptions($requirement, $patternsByCode)
            ) {
                $warnings[] = $this->missingRequirementWarning($store, $date);

                continue;
            }

            $dateShifts = $targetShiftsByDate->get(
                $date->toDateString(),
                collect(),
            );
            $eligibleShifts = $dateShifts->filter(
                fn (Shift $shift): bool => in_array(
                    $shift->pattern_code,
                    self::TARGET_CODES,
                    true,
                ) && $this->isEligibleStaffShift($shift, $store, $date),
            );
            $counts = collect(self::TARGET_CODES)
                ->mapWithKeys(fn (string $code): array => [
                    $code => $eligibleShifts
                        ->where('pattern_code', $code)
                        ->pluck('user_id')
                        ->unique()
                        ->count(),
                ])
                ->all();
            $comparisons = $this->optionComparisons(
                $requirement,
                $counts,
                $patternsByCode,
            );

            if ($comparisons->contains(
                fn (array $comparison): bool => $comparison['missing_total'] === 0
                    && $comparison['excess_total'] === 0,
            )) {
                continue;
            }

            $comparison = $comparisons
                ->sortBy(fn (array $candidate): string => sprintf(
                    '%010d|%010d|%010d|%010d',
                    $candidate['missing_total'],
                    $candidate['excess_total'],
                    $candidate['display_order'],
                    $candidate['option_id'],
                ))
                ->first();
            $missingRanges = collect($comparison['missing_counts'])
                ->filter(fn (int $count): bool => $count > 0)
                ->keys()
                ->map(fn (string $code): string => $this->timeRangeLabel(
                    $patternsByCode->get($code),
                ))
                ->unique()
                ->values()
                ->all();

            if ($missingRanges !== []) {
                $warnings[] = $this->staffingWarning(
                    DraftShiftWarningCode::StaffingShortage,
                    $store,
                    $date,
                    $counts,
                    $eligibleShifts,
                    [
                        'missing_time_ranges' => $missingRanges,
                        'missing_pattern_counts' => $comparison['missing_counts'],
                    ],
                    sprintf(
                        '%sは%sの勤務配置が不足しています。',
                        $date->format('n月j日'),
                        implode('、', $missingRanges),
                    ),
                );

                continue;
            }

            $excessByRange = collect($comparison['excess_counts'])
                ->filter(fn (int $count): bool => $count > 0)
                ->map(fn (int $count, string $code): array => [
                    'pattern_code' => $code,
                    'time_range' => $this->timeRangeLabel($patternsByCode->get($code)),
                    'excess_count' => $count,
                ])
                ->values()
                ->all();

            $warnings[] = $this->staffingWarning(
                DraftShiftWarningCode::StaffingExcess,
                $store,
                $date,
                $counts,
                $eligibleShifts,
                [
                    'excess_time_ranges' => array_column(
                        $excessByRange,
                        'time_range',
                    ),
                    'excess_by_time_range' => $excessByRange,
                    'excess_pattern_counts' => $comparison['excess_counts'],
                    'excess_count' => max(
                        array_column($excessByRange, 'excess_count'),
                    ),
                ],
                sprintf(
                    '%sは%sの配置が超過しています。',
                    $date->format('n月j日'),
                    collect($excessByRange)->pluck('time_range')->implode('、'),
                ),
            );
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, StoreStaffingRequirement>  $requirements
     */
    private function applicableRequirement(
        Collection $requirements,
        CarbonImmutable $date,
    ): ?StoreStaffingRequirement {
        return $requirements
            ->filter(function (StoreStaffingRequirement $requirement) use ($date): bool {
                $withinPeriod = (
                    $requirement->effective_from === null
                    || $requirement->effective_from->lte($date)
                ) && (
                    $requirement->effective_to === null
                    || $requirement->effective_to->gte($date)
                );

                if (! $withinPeriod) {
                    return false;
                }

                if ($requirement->work_date !== null) {
                    return $requirement->work_date->isSameDay($date);
                }

                return $requirement->weekday === null
                    || (int) $requirement->weekday === $date->dayOfWeek;
            })
            ->sortByDesc(fn (StoreStaffingRequirement $requirement): string => sprintf(
                '%d|%s|%010d',
                $requirement->work_date !== null
                    ? 3
                    : ($requirement->weekday !== null ? 2 : 1),
                $requirement->effective_from?->format('Y-m-d') ?? '0000-00-00',
                $requirement->getKey(),
            ))
            ->first();
    }

    /**
     * @param  SupportCollection<string, StoreShiftPattern>  $patternsByCode
     */
    private function hasUsableOptions(
        StoreStaffingRequirement $requirement,
        SupportCollection $patternsByCode,
    ): bool {
        return $this->optionRequirements($requirement, $patternsByCode)
            ->isNotEmpty();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  SupportCollection<string, StoreShiftPattern>  $patternsByCode
     * @return SupportCollection<int, array{
     *     option_id: int,
     *     display_order: int,
     *     missing_counts: array<string, int>,
     *     excess_counts: array<string, int>,
     *     missing_total: int,
     *     excess_total: int
     * }>
     */
    private function optionComparisons(
        StoreStaffingRequirement $requirement,
        array $counts,
        SupportCollection $patternsByCode,
    ): SupportCollection {
        return $this->optionRequirements($requirement, $patternsByCode)
            ->map(function (array $option) use ($counts): array {
                $missingCounts = [];
                $excessCounts = [];

                foreach (self::TARGET_CODES as $code) {
                    $requiredCount = $option['required_counts'][$code];
                    $actualCount = $counts[$code];
                    $missingCounts[$code] = max(0, $requiredCount - $actualCount);
                    $excessCounts[$code] = max(0, $actualCount - $requiredCount);
                }

                return [
                    'option_id' => $option['option_id'],
                    'display_order' => $option['display_order'],
                    'missing_counts' => $missingCounts,
                    'excess_counts' => $excessCounts,
                    'missing_total' => array_sum($missingCounts),
                    'excess_total' => array_sum($excessCounts),
                ];
            })
            ->values();
    }

    /**
     * @param  SupportCollection<string, StoreShiftPattern>  $patternsByCode
     * @return SupportCollection<int, array{
     *     option_id: int,
     *     display_order: int,
     *     required_counts: array<string, int>
     * }>
     */
    private function optionRequirements(
        StoreStaffingRequirement $requirement,
        SupportCollection $patternsByCode,
    ): SupportCollection {
        return $requirement->options
            ->map(function ($option) use ($patternsByCode): ?array {
                $requiredCounts = array_fill_keys(self::TARGET_CODES, 0);

                foreach ($option->patterns as $optionPattern) {
                    $configuredPattern = $optionPattern->shiftPattern;
                    $code = $configuredPattern?->code;
                    $storePattern = $code !== null
                        ? $patternsByCode->get($code)
                        : null;

                    if (
                        $code === null
                        || ! array_key_exists($code, $requiredCounts)
                        || ! $storePattern instanceof StoreShiftPattern
                        || (int) $configuredPattern->getKey()
                            !== (int) $storePattern->getKey()
                        || $storePattern->start_time === null
                        || $storePattern->start_day_offset === null
                        || $storePattern->end_time === null
                        || $storePattern->end_day_offset === null
                    ) {
                        return null;
                    }

                    $requiredCounts[$code] = (int) $optionPattern->required_count;
                }

                if (array_sum($requiredCounts) === 0) {
                    return null;
                }

                return [
                    'option_id' => (int) $option->getKey(),
                    'display_order' => (int) $option->display_order,
                    'required_counts' => $requiredCounts,
                ];
            })
            ->filter()
            ->values();
    }

    private function isEligibleStaffShift(
        Shift $shift,
        Store $store,
        CarbonImmutable $workDate,
    ): bool {
        $user = $shift->user;

        if (
            ! $user instanceof User
            || $user->status !== 'active'
            || (int) $user->organization_id !== (int) $store->organization_id
            || ! $user->hasRole('staff')
        ) {
            return false;
        }

        $membership = $user->stores->first(
            fn (Store $membershipStore): bool => (int) $membershipStore->getKey()
                === (int) $store->getKey(),
        );

        if (! $membership instanceof Store || ! (bool) $membership->pivot->is_active) {
            return false;
        }

        $date = $workDate->toDateString();
        $startedOn = $membership->pivot->started_on;
        $endedOn = $membership->pivot->ended_on;

        return ($startedOn === null || (string) $startedOn <= $date)
            && ($endedOn === null || (string) $endedOn >= $date);
    }

    /**
     * @param  array<string, int>  $counts
     * @param  SupportCollection<int, Shift>  $eligibleShifts
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function staffingWarning(
        DraftShiftWarningCode $code,
        Store $store,
        CarbonImmutable $date,
        array $counts,
        SupportCollection $eligibleShifts,
        array $details,
        string $message,
    ): array {
        return [
            'warning_code' => $code->value,
            'severity' => 'error',
            'blocking' => true,
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->getKey(),
            'store_name' => $store->name,
            'store_ids' => [(int) $store->getKey()],
            'store_names' => [$store->name],
            'work_date' => $date->toDateString(),
            'current_b_count' => $counts['B'],
            'current_c_count' => $counts['C'],
            'current_d_count' => $counts['D'],
            'current_counts' => $counts,
            'shift_ids' => $eligibleShifts
                ->pluck('id')
                ->map(fn (int $id): int => $id)
                ->values()
                ->all(),
            'shift_patterns' => $eligibleShifts
                ->map(fn (Shift $shift): array => [
                    'shift_id' => (int) $shift->getKey(),
                    'user_id' => (int) $shift->user_id,
                    'code' => (string) $shift->pattern_code,
                ])
                ->values()
                ->all(),
            ...$details,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingRequirementWarning(
        Store $store,
        CarbonImmutable $date,
    ): array {
        return [
            'warning_code' => DraftShiftWarningCode::StaffingRequirementMissing->value,
            'severity' => 'error',
            'blocking' => true,
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->getKey(),
            'store_name' => $store->name,
            'store_ids' => [(int) $store->getKey()],
            'store_names' => [$store->name],
            'work_date' => $date->toDateString(),
            'requires_configuration' => true,
            'message' => sprintf(
                '%sの必要配置が設定されていません。配布前に設定してください。',
                $date->format('n月j日'),
            ),
        ];
    }

    private function timeRangeLabel(?StoreShiftPattern $pattern): string
    {
        if (
            ! $pattern instanceof StoreShiftPattern
            || $pattern->start_time === null
            || $pattern->end_time === null
        ) {
            return '必要勤務時間帯';
        }

        $start = substr((string) $pattern->start_time, 0, 5);
        $end = substr((string) $pattern->end_time, 0, 5);
        $endPrefix = (int) $pattern->end_day_offset
            > (int) $pattern->start_day_offset ? '翌' : '';

        return "{$start}から{$endPrefix}{$end}";
    }
}
