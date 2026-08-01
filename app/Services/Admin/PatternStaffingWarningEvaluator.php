<?php

namespace App\Services\Admin;

use App\Enums\DraftShiftWarningCode;
use App\Models\Shift;
use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 店舗の必要配置パターンと下書きシフトを比較して警告を生成します。
 */
final class PatternStaffingWarningEvaluator
{
    public const TARGET_CODES = ['B', 'C', 'D'];

    /**
     * @param  SupportCollection<int, Shift>  $organizationShifts
     * @param  Collection<int, StoreStaffingRequirement>  $requirements
     * @param  SupportCollection<string, StoreShiftPattern>  $patternsByCode
     * @return list<array<string, mixed>>
     */
    public function evaluate(
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
                ) && $this->isEligibleStaffShift($shift, $store),
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
    ): bool {
        $user = $shift->user;

        return $user instanceof User
            && $user->status === 'active'
            && (int) $user->organization_id === (int) $store->organization_id
            && $user->hasRole('staff');
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
