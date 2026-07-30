<?php

namespace App\Services\Admin;

use App\Models\Store;
use App\Models\StoreShiftPattern;
use App\Models\StoreStaffingRequirement;
use App\Models\StoreStaffingRequirementOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminStoreAssociationService
{
    /**
     * 店舗詳細画面の全項目を単一トランザクションで保存します。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateStoreDetails(
        Store $store,
        User $actor,
        array $attributes,
        bool $canManageManagers,
    ): void {
        DB::transaction(function () use (
            $store,
            $actor,
            $attributes,
            $canManageManagers,
        ): void {
            $lockedStore = $this->lockStore($store);

            if ((int) $actor->organization_id !== (int) $lockedStore->organization_id) {
                abort(403);
            }

            $this->updateBasicInformation($lockedStore, $attributes);
            $this->syncStaffMemberships(
                $lockedStore,
                $this->integerIds($attributes['staff_user_ids'] ?? []),
            );

            if ($canManageManagers) {
                $this->syncShiftManagers(
                    $lockedStore,
                    $this->integerIds($attributes['manager_user_ids'] ?? []),
                );
            }

            $this->syncShiftPatterns(
                $lockedStore,
                array_values($attributes['patterns'] ?? []),
            );
            $this->updateStaffing($lockedStore, $attributes);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateBasicInformation(
        Store $store,
        array $attributes,
    ): void {
        $area = $attributes['area'] ?? null;

        $store->forceFill([
            'name' => trim((string) $attributes['name']),
            'area' => is_string($area) && trim($area) !== ''
                ? trim($area)
                : null,
        ])->save();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function syncStaffMemberships(Store $store, array $userIds): void
    {
        $this->assertEligibleUsers($store, $userIds, 'staff');

        $existing = DB::table('store_user')
            ->where('store_id', $store->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');
        $today = $this->today();
        $now = now();
        $nextOrder = (int) $existing->max('display_order');

        foreach ($userIds as $userId) {
            $pivot = $existing->get($userId);

            if ($pivot !== null) {
                DB::table('store_user')
                    ->where('id', $pivot->id)
                    ->update([
                        'is_active' => true,
                        'started_on' => $pivot->started_on !== null
                            && $pivot->started_on <= $today
                                ? $pivot->started_on
                                : $today,
                        'ended_on' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            $nextOrder++;
            DB::table('store_user')->insert([
                'store_id' => $store->getKey(),
                'user_id' => $userId,
                'display_order' => $nextOrder,
                'is_active' => true,
                'started_on' => $today,
                'ended_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($existing as $pivot) {
            if (
                in_array((int) $pivot->user_id, $userIds, true)
                || ! $this->isCurrentMembership($pivot, $today)
            ) {
                continue;
            }

            DB::table('store_user')
                ->where('id', $pivot->id)
                ->update([
                    'is_active' => false,
                    'ended_on' => $this->endedOn($pivot, $today),
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function syncShiftManagers(Store $store, array $userIds): void
    {
        $this->assertEligibleUsers($store, $userIds, 'shift_manager');

        $existing = DB::table('store_shift_manager')
            ->where('store_id', $store->getKey())
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id');
        $today = $this->today();
        $now = now();

        foreach ($userIds as $userId) {
            $pivot = $existing->get($userId);

            if ($pivot !== null) {
                DB::table('store_shift_manager')
                    ->where('id', $pivot->id)
                    ->update([
                        'is_active' => true,
                        'started_on' => $pivot->started_on !== null
                            && $pivot->started_on <= $today
                                ? $pivot->started_on
                                : $today,
                        'ended_on' => null,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('store_shift_manager')->insert([
                'store_id' => $store->getKey(),
                'user_id' => $userId,
                'is_active' => true,
                'started_on' => $today,
                'ended_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($existing as $pivot) {
            if (
                in_array((int) $pivot->user_id, $userIds, true)
                || ! $this->isCurrentMembership($pivot, $today)
            ) {
                continue;
            }

            DB::table('store_shift_manager')
                ->where('id', $pivot->id)
                ->update([
                    'is_active' => false,
                    'ended_on' => $this->endedOn($pivot, $today),
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $patterns
     */
    private function syncShiftPatterns(Store $store, array $patterns): void
    {
        $existing = $store->shiftPatterns()
            ->withTrashed()
            ->lockForUpdate()
            ->get();
        $existingById = $existing->keyBy('id');
        $submittedCodes = [];
        $activeIds = [];

        foreach ($patterns as $index => $attributes) {
            $code = trim((string) $attributes['code']);

            if (in_array($code, $submittedCodes, true)) {
                throw ValidationException::withMessages([
                    "patterns.{$index}.code" => 'パターンコードが重複しています。',
                ]);
            }
            $submittedCodes[] = $code;

            $id = isset($attributes['id']) ? (int) $attributes['id'] : null;
            $pattern = $id === null ? null : $existingById->get($id);

            if ($id !== null && ! $pattern instanceof StoreShiftPattern) {
                throw ValidationException::withMessages([
                    "patterns.{$index}.id" => '対象店舗のシフトパターンではありません。',
                ]);
            }

            if (! $pattern instanceof StoreShiftPattern) {
                $pattern = $existing->first(
                    fn (StoreShiftPattern $candidate): bool => $candidate->code === $code,
                );
            }

            if (
                $existing->contains(
                    fn (StoreShiftPattern $candidate): bool => $candidate->code === $code
                        && (! $pattern instanceof StoreShiftPattern
                            || (int) $candidate->getKey() !== (int) $pattern->getKey()),
                )
            ) {
                throw ValidationException::withMessages([
                    "patterns.{$index}.code" => 'このパターンコードは既に使用されています。',
                ]);
            }

            $startTime = $attributes['start_time'] ?? null;
            $endTime = $attributes['end_time'] ?? null;

            if (($startTime === null) xor ($endTime === null)) {
                throw ValidationException::withMessages([
                    "patterns.{$index}.start_time" => '開始時刻と終了時刻は両方入力してください。',
                ]);
            }

            [$startDayOffset, $endDayOffset] = $this->patternDayOffsets(
                $pattern,
                $startTime,
                $endTime,
            );
            $values = [
                'code' => $code,
                'work_hours' => $attributes['work_hours'],
                'start_time' => $startTime,
                'start_day_offset' => $startDayOffset,
                'end_time' => $endTime,
                'end_day_offset' => $endDayOffset,
                'display_order' => $index + 1,
                'is_active' => true,
                'deleted_at' => null,
            ];

            if ($pattern instanceof StoreShiftPattern) {
                $pattern->forceFill($values)->save();
            } else {
                $pattern = $store->shiftPatterns()->create($values);
            }

            $activeIds[] = (int) $pattern->getKey();
        }

        $store->shiftPatterns()
            ->where('is_active', true)
            ->when(
                $activeIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn(
                    'id',
                    $activeIds,
                ),
            )
            ->update(['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateStaffing(Store $store, array $attributes): void
    {
        $mode = (string) $attributes['staffing_check_mode'];

        $store->forceFill([
            'staffing_check_mode' => $mode,
            'required_staff_count' => $mode === 'fixed_total'
                ? $attributes['required_staff_count']
                : null,
        ])->save();

        if ($mode !== 'pattern_combinations') {
            return;
        }

        $options = array_values($attributes['staffing_options'] ?? []);

        if ($options === []) {
            return;
        }

        $requirement = $store->staffingRequirements()
            ->whereNull('work_date')
            ->whereNull('weekday')
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $this->today());
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $this->today());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $requirement instanceof StoreStaffingRequirement) {
            $requirement = $store->staffingRequirements()->create([
                'work_date' => null,
                'weekday' => null,
                'effective_from' => $this->today(),
                'effective_to' => null,
                'is_active' => true,
            ]);
        }

        $existingOptions = $requirement->options()
            ->with('patterns')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $codes = [];

        foreach ($options as $index => $optionAttributes) {
            $id = isset($optionAttributes['id'])
                ? (int) $optionAttributes['id']
                : null;
            $option = $id === null ? null : $existingOptions->get($id);

            if ($id !== null && ! $option instanceof StoreStaffingRequirementOption) {
                throw ValidationException::withMessages([
                    "staffing_options.{$index}.id" => '対象店舗の人数配置選択肢ではありません。',
                ]);
            }

            if ((bool) ($optionAttributes['remove'] ?? false)) {
                $option?->delete();

                continue;
            }

            $code = trim((string) ($optionAttributes['code'] ?? ''));

            if ($code === '' && $id === null) {
                continue;
            }

            if ($code === '' || in_array($code, $codes, true)) {
                throw ValidationException::withMessages([
                    "staffing_options.{$index}.code" => '選択肢コードを重複なく入力してください。',
                ]);
            }
            $codes[] = $code;

            if (
                $requirement->options()
                    ->where('code', $code)
                    ->when(
                        $option,
                        fn (Builder $query): Builder => $query->whereKeyNot(
                            $option->getKey(),
                        ),
                    )
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    "staffing_options.{$index}.code" => 'この選択肢コードは既に使用されています。',
                ]);
            }

            if (! $option instanceof StoreStaffingRequirementOption) {
                $option = $requirement->options()->create([
                    'code' => $code,
                    'display_order' => (int) ($optionAttributes['display_order'] ?? 0),
                ]);
            } else {
                $option->fill([
                    'code' => $code,
                    'display_order' => (int) ($optionAttributes['display_order'] ?? 0),
                ])->save();
            }

            $patternCounts = collect($optionAttributes['pattern_counts'] ?? [])
                ->filter(fn (mixed $count): bool => $count !== null && $count !== '')
                ->mapWithKeys(
                    fn (mixed $count, mixed $patternId): array => [
                        (int) $patternId => (int) $count,
                    ],
                );
            $patternIds = $patternCounts->keys()->all();
            $eligibleCount = $store->shiftPatterns()
                ->where('is_active', true)
                ->whereKey($patternIds)
                ->count();

            if ($eligibleCount !== count($patternIds)) {
                throw ValidationException::withMessages([
                    "staffing_options.{$index}.pattern_counts" => '対象店舗のシフトパターンだけを指定してください。',
                ]);
            }

            $option->patterns()
                ->when(
                    $patternIds !== [],
                    fn (Builder $query): Builder => $query->whereNotIn(
                        'store_shift_pattern_id',
                        $patternIds,
                    ),
                )
                ->delete();

            foreach ($patternCounts as $patternId => $requiredCount) {
                $option->patterns()->updateOrCreate(
                    ['store_shift_pattern_id' => $patternId],
                    ['required_count' => $requiredCount],
                );
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertEligibleUsers(
        Store $store,
        array $userIds,
        string $roleCode,
    ): void {
        $eligibleCount = User::query()
            ->where('organization_id', $store->organization_id)
            ->where('status', 'active')
            ->whereKey($userIds)
            ->whereHas('roles', fn (Builder $query): Builder => $query->where(
                'code',
                $roleCode,
            ))
            ->count();

        if ($eligibleCount !== count($userIds)) {
            throw ValidationException::withMessages([
                'users' => '同一組織の有効な対象ユーザーだけを指定してください。',
            ]);
        }
    }

    private function lockStore(Store $store): Store
    {
        return Store::query()
            ->whereKey($store->getKey())
            ->where('organization_id', $store->organization_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        return collect($ids)
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function patternDayOffsets(
        ?StoreShiftPattern $pattern,
        mixed $startTime,
        mixed $endTime,
    ): array {
        if ($startTime === null || $endTime === null) {
            return [null, null];
        }

        if (
            $pattern instanceof StoreShiftPattern
            && $pattern->start_day_offset !== null
            && $pattern->end_day_offset !== null
        ) {
            return [
                (int) $pattern->start_day_offset,
                (int) $pattern->end_day_offset,
            ];
        }

        return [0, (string) $endTime <= (string) $startTime ? 1 : 0];
    }

    private function today(): string
    {
        return CarbonImmutable::now(
            (string) config('app.timezone', 'Asia/Tokyo'),
        )->toDateString();
    }

    private function isCurrentMembership(object $membership, string $today): bool
    {
        return (bool) $membership->is_active
            && (
                $membership->started_on === null
                || $membership->started_on <= $today
            )
            && (
                $membership->ended_on === null
                || $membership->ended_on >= $today
            );
    }

    private function endedOn(object $membership, string $today): string
    {
        return $membership->started_on !== null
            && $membership->started_on > $today
                ? $membership->started_on
                : $today;
    }
}
