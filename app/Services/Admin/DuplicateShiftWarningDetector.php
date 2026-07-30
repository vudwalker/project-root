<?php

namespace App\Services\Admin;

use App\Enums\DraftShiftWarningCode;
use App\Models\Shift;
use App\Models\Store;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 管理者用下書きから同一勤務基準日の重複勤務警告を生成します。
 */
final class DuplicateShiftWarningDetector
{
    /**
     * @param  SupportCollection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    public function detect(
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
}
