<?php

namespace Database\Seeders;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\StoreStaffingRequirementOption;
use App\Models\StoreStaffingRequirementOptionPattern;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StaffingRequirementSeeder extends Seeder
{
    private const EFFECTIVE_FROM = '2026-07-01';

    /**
     * @var array<string, array<string, array{
     *     display_order: int,
     *     patterns: array<string, int>
     * }>>
     */
    private const OPTIONS_BY_STORE = [
        'daianji' => [
            'full-c' => [
                'display_order' => 10,
                'patterns' => ['C' => 1],
            ],
        ],
        'noda' => [
            'full-c' => [
                'display_order' => 10,
                'patterns' => ['C' => 1],
            ],
        ],
        'saidaiji' => [
            'split-b-c' => [
                'display_order' => 10,
                'patterns' => ['B' => 1, 'C' => 1],
            ],
            'full-d' => [
                'display_order' => 20,
                'patterns' => ['D' => 1],
            ],
        ],
        'okayama-tomida' => [
            'full-c' => [
                'display_order' => 10,
                'patterns' => ['C' => 1],
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            Store::query()
                ->whereIn('code', array_keys(self::OPTIONS_BY_STORE))
                ->with('shiftPatterns')
                ->each(function (Store $store): void {
                    $store->forceFill([
                        'staffing_check_mode' => 'pattern_combinations',
                        'required_staff_count' => null,
                    ])->save();

                    $patterns = $store->shiftPatterns->keyBy('code');
                    $requirement = StoreStaffingRequirement::query()
                        ->withTrashed()
                        ->where('store_id', $store->getKey())
                        ->whereNull('work_date')
                        ->whereNull('weekday')
                        ->whereDate('effective_from', self::EFFECTIVE_FROM)
                        ->first();

                    if (! $requirement instanceof StoreStaffingRequirement) {
                        $requirement = StoreStaffingRequirement::query()->create([
                            'store_id' => $store->getKey(),
                            'work_date' => null,
                            'weekday' => null,
                            'effective_from' => self::EFFECTIVE_FROM,
                            'effective_to' => null,
                            'is_active' => true,
                        ]);
                    } else {
                        $requirement->forceFill([
                            'effective_to' => null,
                            'is_active' => true,
                            'deleted_at' => null,
                        ])->save();
                    }

                    $storeOptions = self::OPTIONS_BY_STORE[$store->code];
                    $requirement->options()
                        ->whereNotIn('code', array_keys($storeOptions))
                        ->delete();

                    foreach ($storeOptions as $code => $optionDefinition) {
                        $requiredCounts = [];

                        foreach ($optionDefinition['patterns'] as $patternCode => $count) {
                            $pattern = $patterns->get($patternCode);

                            if (! $pattern instanceof StoreShiftPattern) {
                                throw new \LogicException(
                                    "{$store->code}のシフトパターン{$patternCode}がありません。",
                                );
                            }

                            $requiredCounts[(int) $pattern->getKey()] = $count;
                        }

                        $this->seedOption(
                            $requirement,
                            $code,
                            $optionDefinition['display_order'],
                            $requiredCounts,
                        );
                    }
                });
        });
    }

    /**
     * @param  array<int, int>  $requiredCounts
     */
    private function seedOption(
        StoreStaffingRequirement $requirement,
        string $code,
        int $displayOrder,
        array $requiredCounts,
    ): void {
        $option = StoreStaffingRequirementOption::query()->updateOrCreate(
            [
                'store_staffing_requirement_id' => $requirement->getKey(),
                'code' => $code,
            ],
            [
                'display_order' => $displayOrder,
            ],
        );
        $option->patterns()
            ->whereNotIn(
                'store_shift_pattern_id',
                array_keys($requiredCounts),
            )
            ->delete();

        foreach ($requiredCounts as $patternId => $requiredCount) {
            StoreStaffingRequirementOptionPattern::query()->updateOrCreate(
                [
                    'store_staffing_requirement_option_id' => $option->getKey(),
                    'store_shift_pattern_id' => $patternId,
                ],
                [
                    'required_count' => $requiredCount,
                ],
            );
        }
    }
}
